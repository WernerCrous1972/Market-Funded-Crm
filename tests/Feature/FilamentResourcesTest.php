<?php

declare(strict_types=1);

use App\Models\User;
use App\Filament\Resources\PersonResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TradingAccountResource;

describe('Filament resources smoke tests', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create(['role' => 'ADMIN']));
    });

    it('people index page loads without error', function () {
        $this->get(PersonResource::getUrl('index'))
            ->assertOk();
    });

    it('transactions index page loads without error', function () {
        $this->get(TransactionResource::getUrl('index'))
            ->assertOk();
    });

    it('trading accounts index page loads without error', function () {
        $this->get(TradingAccountResource::getUrl('index'))
            ->assertOk();
    });

    it('unauthenticated user is redirected to login', function () {
        auth()->logout();
        $this->get(PersonResource::getUrl('index'))
            ->assertRedirect('/admin/login');
    });
});
