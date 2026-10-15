<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('evacuation.admin', fn (User $user) => $user->role === User::ROLE_ADMIN);
