<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Any authenticated admin may receive CRM alerts
Broadcast::channel('crm-alerts', fn ($user) => $user !== null);
