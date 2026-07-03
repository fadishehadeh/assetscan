<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /index.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        header('Location: /modules/dashboard/index.php?error=unauthorized');
        exit;
    }
}

function isSuperAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'super_admin';
}

function isAdmin(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['super_admin', 'admin'], true);
}

function isIT(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['super_admin', 'admin', 'it'], true);
}

function currentUser(): array {
    return [
        'id'   => $_SESSION['user_id']   ?? 0,
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
    ];
}

function roleLabel(string $role): string {
    return match($role) {
        'super_admin' => 'Super Admin',
        'admin'       => 'Admin',
        'it'          => 'IT',
        default       => ucfirst($role),
    };
}

function roleBadgeClass(string $role): string {
    return match($role) {
        'super_admin' => 'bg-danger',
        'admin'       => 'bg-primary',
        'it'          => 'bg-info text-dark',
        default       => 'bg-secondary',
    };
}
