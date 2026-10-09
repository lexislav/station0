<?php

use Delight\Auth\Role;

return [
    'admin' => Role::ADMIN,
    'editor' => Role::EDITOR,
    // Public-site accounts (members-only access) — no admin access, see `admin.roles`.
    'member' => Role::SUBSCRIBER,
];
