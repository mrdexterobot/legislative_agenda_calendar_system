<?php
/**
 * password_policy.php — one definition of "strong enough", used by every
 * place a password can be set: admin account creation, admin password reset,
 * self-service change, and the forgot-password flow.
 *
 * Previously each of those four endpoints enforced its own rule (all of them
 * just "at least 8 characters"), which meant the policy could drift apart as
 * endpoints were edited independently. The audit checklist asks for an
 * enforced password policy; a single shared validator is the thing to point
 * at as evidence.
 *
 * Deliberately not maximal: length plus a letter/number mix, no forced
 * symbols and no forced rotation. NIST SP 800-63B specifically advises
 * against composition rules so aggressive that people write passwords down,
 * which in a shared government office is the realistic failure mode.
 */

const PASSWORD_MIN_LENGTH = 8;

/** Returns a list of problems. Empty array = acceptable. */
function passwordPolicyIssues(string $password, array $context = []): array {
    $issues = [];

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $issues[] = 'It must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password)) {
        $issues[] = 'It must contain at least one letter.';
    }
    if (!preg_match('/\d/', $password)) {
        $issues[] = 'It must contain at least one number.';
    }

    // Reject the obvious ones outright, including the seeded default — an
    // account still carrying ChangeMe123! after deployment is the single most
    // likely way into this system.
    $banned = ['password', 'password1', '12345678', 'changeme123!', 'qwerty123', 'admin123'];
    if (in_array(strtolower($password), $banned, true)) {
        $issues[] = 'That password is too common — choose something specific to you.';
    }

    // Don't let the password simply be the username or email local part.
    foreach (['username', 'email'] as $field) {
        if (!empty($context[$field])) {
            $needle = strtolower(explode('@', (string) $context[$field])[0]);
            if ($needle !== '' && strtolower($password) === $needle) {
                $issues[] = 'It cannot be the same as your ' . $field . '.';
            }
        }
    }

    return $issues;
}

/** Convenience: one sentence ready to hand back to the client, or null. */
function passwordPolicyError(string $password, array $context = []): ?string {
    $issues = passwordPolicyIssues($password, $context);
    return $issues ? 'That password does not meet the policy: ' . implode(' ', $issues) : null;
}
