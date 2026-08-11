<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Nothing in this suite may reach the network, shell out, or wait.
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        // Almost every rule in this app is a date boundary, so time stands
        // still unless a test moves it on purpose.
        $this->freezeTime();
    })
    ->in('Arch', 'Feature', 'Unit');

// Browser tests are grouped so `composer test:unit` can skip them and
// `composer test:browser` can find them.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->in('Browser');
