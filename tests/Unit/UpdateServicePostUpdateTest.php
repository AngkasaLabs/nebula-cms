<?php

use App\Services\UpdateService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('clears every cache and runs migrations after an update', function () {
    Artisan::spy();

    $service = new class extends UpdateService
    {
        public function runTasks(): void
        {
            $this->runPostUpdateTasks();
        }
    };

    $service->runTasks();

    Artisan::shouldHaveReceived('call')->with('migrate', ['--force' => true])->once();
    Artisan::shouldHaveReceived('call')->with('optimize:clear')->once();
});
