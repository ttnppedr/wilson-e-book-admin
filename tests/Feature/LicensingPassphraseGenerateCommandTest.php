<?php

/**
 * licensing-passphrase:generate 指令。
 *
 * LICENSING_KEY_PASSPHRASE 是 root / signing 私鑰的解密金鑰：被覆寫後既有私鑰永久
 * 無法解密，activate 與 validate 會全面 500。本檔守護「已有值時不得被靜默覆寫」。
 * 指令只碰暫存目錄裡的 .env，不會動到專案的 .env。
 */
beforeEach(function (): void {
    $this->environmentDirectory = sys_get_temp_dir().'/passphrase-test-'.bin2hex(random_bytes(6));
    mkdir($this->environmentDirectory);
    $this->app->useEnvironmentPath($this->environmentDirectory);
    $this->environmentFile = $this->environmentDirectory.'/.env';
});

afterEach(function (): void {
    @unlink($this->environmentFile);
    @rmdir($this->environmentDirectory);
});

test('writes a passphrase when none is set', function (): void {
    file_put_contents($this->environmentFile, "APP_NAME=Wilson\nLICENSING_KEY_PASSPHRASE=\n");

    $this->artisan('licensing-passphrase:generate')->assertExitCode(0);

    $this->assertMatchesRegularExpression(
        '/^LICENSING_KEY_PASSPHRASE="[A-Za-z0-9+\/]{43}="$/m',
        file_get_contents($this->environmentFile),
    );
});

test('refuses to overwrite an existing passphrase without force', function (): void {
    $original = "LICENSING_KEY_PASSPHRASE=\"existing-production-passphrase\"\n";
    file_put_contents($this->environmentFile, $original);

    $this->artisan('licensing-passphrase:generate')->assertExitCode(1);

    $this->assertSame($original, file_get_contents($this->environmentFile));
});

test('force still asks for confirmation before overwriting', function (): void {
    $original = "LICENSING_KEY_PASSPHRASE=\"existing-production-passphrase\"\n";
    file_put_contents($this->environmentFile, $original);

    $this->artisan('licensing-passphrase:generate', ['--force' => true])
        ->expectsConfirmation('覆寫既有 passphrase 會讓既有 root 私鑰永久無法解密，確認繼續？', 'no')
        ->assertExitCode(1);

    $this->assertSame($original, file_get_contents($this->environmentFile));
});

test('show prints a passphrase without touching the env file', function (): void {
    $original = "LICENSING_KEY_PASSPHRASE=\"existing-production-passphrase\"\n";
    file_put_contents($this->environmentFile, $original);

    $this->artisan('licensing-passphrase:generate', ['--show' => true])->assertExitCode(0);

    $this->assertSame($original, file_get_contents($this->environmentFile));
});
