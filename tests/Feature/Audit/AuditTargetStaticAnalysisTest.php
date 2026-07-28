<?php

/**
 * 用 nikic/php-parser AST 分析 app/ 下的 PHP 檔，比 grep 精準偵測：
 *   1. Eloquent quiet API：saveQuietly / updateQuietly / deleteQuietly / withoutEvents
 *   2. 對 audit target model 的 static mass update/delete：Model::where(...)->update()
 *
 * AST 比 grep 強的地方：
 *   - 透過 NameResolver 把 use alias 還原為 FQN：`use App\Models\License as L;` 後 `L::query()` 仍能辨識
 *   - 區分 static 呼叫 vs 實例呼叫，避免 grep 誤判
 *
 * 偽陽性處理：app/Models/Concerns/EnforcesAuditEvents.php 是定義 quiet API 拒絕語意的檔案本身，
 * 透過 $allowedFiles 名單豁免。
 */

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** @var list<class-string> 新增 audit 對象 model 時要同步加進這個清單 */
$auditTargetModels = [
    License::class,
    LicenseScope::class,
    LicenseUsage::class,
];

$quietMethods = [
    'saveQuietly',
    'updateQuietly',
    'deleteQuietly',
    'withoutEvents',
];

/** @var list<string> file paths (relative to project root) that may legitimately reference these APIs */
$allowedFiles = [
    'app/Models/Concerns/EnforcesAuditEvents.php',
];

/**
 * @return iterable<string, array<Node>>
 */
$scanAppFiles = function (): iterable {
    $root = dirname(__DIR__, 3);
    $appDir = $root.'/app';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
    );

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new NameResolver);

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1);
        $code = file_get_contents($file->getPathname());

        try {
            $ast = $parser->parse($code) ?? [];
        } catch (Throwable) {
            continue;
        }

        $resolved = $traverser->traverse($ast);

        yield $relative => $resolved;
    }
};

$isAllowedFile = function (string $relativePath) use ($allowedFiles): bool {
    return in_array($relativePath, $allowedFiles, true);
};

/**
 * 沿 method call chain 往上找根呼叫者，若是 Static call 則解析其 class FQN。
 *
 * 例如 `License::where(...)->update(...)` 的 chain：
 *   MethodCall(update) → MethodCall(where) → StaticCall(License::query) ... 但 where 是 Static method on Model
 *
 * Eloquent 慣用 `Model::where(...)` 是 StaticCall，後面 ->update 是 MethodCall。
 * 此方法沿 var/caller 鏈往上找到 StaticCall，回傳其 class FQN。
 */
$resolveStaticCallRoot = function (MethodCall $call): ?string {
    $current = $call->var;

    while ($current !== null) {
        if ($current instanceof StaticCall) {
            if (! $current->class instanceof Node\Name) {
                return null;
            }

            return $current->class->toString();
        }

        if ($current instanceof MethodCall) {
            $current = $current->var;

            continue;
        }

        return null;
    }

    return null;
};

test('no quiet api calls in app directory', function () use ($scanAppFiles, $isAllowedFile, $quietMethods): void {
    $offenses = [];

    foreach ($scanAppFiles() as $file => $ast) {
        if ($isAllowedFile($file)) {
            continue;
        }

        $finder = new NodeFinder;
        $methodCalls = $finder->find($ast, fn (Node $node) => $node instanceof MethodCall || $node instanceof StaticCall);

        foreach ($methodCalls as $call) {
            $name = $call->name instanceof Identifier ? $call->name->toString() : null;
            if (! in_array($name, $quietMethods, true)) {
                continue;
            }

            $offenses[] = sprintf(
                '%s:%d → %s 呼叫繞過 audit',
                $file,
                $call->getStartLine(),
                $name,
            );
        }
    }

    $this->assertSame(
        [],
        $offenses,
        "偵測到繞過 audit 的 API 呼叫：\n  - ".implode("\n  - ", $offenses)
        ."\n若有合法用途請加入 tests/Feature/Audit/AuditTargetStaticAnalysisTest.php 的 \$allowedFiles。",
    );
});

test('no mass update or delete on audit target models', function () use ($scanAppFiles, $isAllowedFile, $resolveStaticCallRoot, $auditTargetModels): void {
    $offenses = [];

    foreach ($scanAppFiles() as $file => $ast) {
        if ($isAllowedFile($file)) {
            continue;
        }

        $finder = new NodeFinder;

        foreach ($finder->find($ast, fn (Node $node) => $node instanceof MethodCall) as $call) {
            $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;
            if (! in_array($methodName, ['update', 'delete'], true)) {
                continue;
            }

            $rootClass = $resolveStaticCallRoot($call);
            if ($rootClass === null) {
                continue;
            }

            if (! in_array($rootClass, $auditTargetModels, true)) {
                continue;
            }

            $offenses[] = sprintf(
                '%s:%d → %s::...->%s() 是 mass %s，會繞過 Eloquent 事件',
                $file,
                $call->getStartLine(),
                class_basename($rootClass),
                $methodName,
                $methodName,
            );
        }
    }

    $this->assertSame(
        [],
        $offenses,
        "偵測到對 audit target model 的 mass update/delete：\n  - ".implode("\n  - ", $offenses)
        ."\n請改為迴圈呼叫 \$record->save()/->delete()，或在 tests/Feature/Audit/AuditTargetStaticAnalysisTest.php 的 \$allowedFiles 加入豁免。",
    );
});
