<?php

namespace Tests\Feature\Audit;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Tests\TestCase;

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
class AuditTargetStaticAnalysisTest extends TestCase
{
    /** @var list<class-string> */
    private const AUDIT_TARGET_MODELS = [
        \App\Models\License::class,
        \App\Models\LicenseScope::class,
        \App\Models\LicenseUsage::class,
    ];

    private const QUIET_METHODS = [
        'saveQuietly',
        'updateQuietly',
        'deleteQuietly',
        'withoutEvents',
    ];

    /** @var list<string> file paths (relative to project root) that may legitimately reference these APIs */
    private const ALLOWED_FILES = [
        'app/Models/Concerns/EnforcesAuditEvents.php',
    ];

    public function test_no_quiet_api_calls_in_app_directory(): void
    {
        $offenses = [];

        foreach ($this->scanAppFiles() as $file => $ast) {
            if ($this->isAllowedFile($file)) {
                continue;
            }

            $finder = new NodeFinder;
            $methodCalls = $finder->find($ast, fn (Node $node) => $node instanceof MethodCall || $node instanceof StaticCall);

            foreach ($methodCalls as $call) {
                $name = $call->name instanceof Identifier ? $call->name->toString() : null;
                if (! in_array($name, self::QUIET_METHODS, true)) {
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
            ."\n若有合法用途請加入 ".self::class.'::ALLOWED_FILES。',
        );
    }

    public function test_no_mass_update_or_delete_on_audit_target_models(): void
    {
        $offenses = [];
        $targetShortNames = array_map('class_basename', self::AUDIT_TARGET_MODELS);

        foreach ($this->scanAppFiles() as $file => $ast) {
            if ($this->isAllowedFile($file)) {
                continue;
            }

            $finder = new NodeFinder;

            foreach ($finder->find($ast, fn (Node $node) => $node instanceof MethodCall) as $call) {
                $methodName = $call->name instanceof Identifier ? $call->name->toString() : null;
                if (! in_array($methodName, ['update', 'delete'], true)) {
                    continue;
                }

                $rootClass = $this->resolveStaticCallRoot($call);
                if ($rootClass === null) {
                    continue;
                }

                if (! in_array($rootClass, self::AUDIT_TARGET_MODELS, true)) {
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
            ."\n請改為迴圈呼叫 \$record->save()/->delete()，或在 ".self::class.'::ALLOWED_FILES 加入豁免。',
        );
    }

    /**
     * 沿 method call chain 往上找根呼叫者，若是 Static call 則解析其 class FQN。
     *
     * 例如 `License::where(...)->update(...)` 的 chain：
     *   MethodCall(update) → MethodCall(where) → StaticCall(License::query) ... 但 where 是 Static method on Model
     *
     * Eloquent 慣用 `Model::where(...)` 是 StaticCall，後面 ->update 是 MethodCall。
     * 此方法沿 var/caller 鏈往上找到 StaticCall，回傳其 class FQN。
     */
    private function resolveStaticCallRoot(MethodCall $call): ?string
    {
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
    }

    /**
     * @return iterable<string, array<Node>>
     */
    private function scanAppFiles(): iterable
    {
        $root = dirname(__DIR__, 3);
        $appDir = $root.'/app';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appDir, \FilesystemIterator::SKIP_DOTS),
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
            } catch (\Throwable) {
                continue;
            }

            $resolved = $traverser->traverse($ast);

            yield $relative => $resolved;
        }
    }

    private function isAllowedFile(string $relativePath): bool
    {
        return in_array($relativePath, self::ALLOWED_FILES, true);
    }
}
