<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateSigningKey extends Command
{
    protected $signature = 'seatmap:generate-signing-key {--show : Print the key instead of writing it to .env}';

    protected $description = 'Generate the key used to sign price snapshots';

    public function handle(): int
    {
        $key = bin2hex(random_bytes(32));

        if ($this->option('show') || ! file_exists(base_path('.env'))) {
            $this->line($key);

            return self::SUCCESS;
        }

        $env = file_get_contents(base_path('.env'));

        $env = preg_match('/^SEATMAP_SIGNING_KEY=.*$/m', $env)
            ? preg_replace('/^SEATMAP_SIGNING_KEY=.*$/m', "SEATMAP_SIGNING_KEY={$key}", $env)
            : rtrim($env)."\nSEATMAP_SIGNING_KEY={$key}\n";

        file_put_contents(base_path('.env'), $env);

        $this->info('Signing key written to .env.');
        $this->warn('Holds signed with the previous key keep working until they expire.');

        return self::SUCCESS;
    }
}
