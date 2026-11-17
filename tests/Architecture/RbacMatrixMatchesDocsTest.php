<?php

/*
 * config/rbac.php must match the approved matrix in
 * mediconnect-docs/docs/rbac-permissions.md (§9: "a permission added in code
 * without a matrix entry fails a CI check").
 *
 * The docs are mounted read-only at /var/www/docs by mediconnect-infrastructure
 * (DOCS_PATH). Where they are not available the check is skipped, not passed.
 */

$docPath = (getenv('MEDICONNECT_DOCS_PATH') ?: '/var/www/docs').'/rbac-permissions.md';

/** @return array{permissions: list<string>, grants: array<string, array<string, string>>} */
function parse_rbac_matrix(string $markdown): array
{
    $section = substr($markdown, (int) strpos($markdown, '## 5. Role × permission matrix'));
    $section = substr($section, 0, (int) strpos($section, 'Footnotes:'));
    $roles = ['ADMIN', 'MEDICAL_ADMIN', 'DOCTOR', 'PATIENT', 'CLINIC_MANAGER', 'CLINIC_STAFF', 'FINANCE_OFFICER', 'SUPPORT_AGENT', 'MODERATOR'];
    $superscripts = '/[⁰¹²³⁴⁵⁶⁷⁸⁹]+/u';
    $permissions = [];
    $grants = array_fill_keys($roles, []);

    foreach (explode("\n", $section) as $line) {
        if (preg_match('/^\| `([a-z_]+\.[a-z_]+)`[⁰¹²³⁴⁵⁶⁷⁸⁹]* \|(.*)\|\s*$/u', $line, $m) !== 1) {
            continue;
        }

        $permissions[] = $m[1];
        $cells = array_map('trim', explode('|', $m[2]));

        foreach ($roles as $i => $role) {
            $scope = preg_replace($superscripts, '', $cells[$i] ?? '');
            if ($scope !== '' && $scope !== null) {
                $grants[$role][$m[1]] = $scope;
            }
        }
    }

    return ['permissions' => $permissions, 'grants' => $grants];
}

it('keeps config/rbac.php identical to the approved matrix', function () use ($docPath) {
    if (! is_readable($docPath)) {
        $this->markTestSkipped("Docs not mounted at {$docPath}");
    }

    $doc = parse_rbac_matrix((string) file_get_contents($docPath));
    $config = require dirname(__DIR__, 2).'/config/rbac.php';

    expect($config['permissions'])->toBe($doc['permissions'])
        ->and($config['grants'])->toBe($doc['grants']);
});

it('lists every spec permission (§3 and §4) in the matrix', function () use ($docPath) {
    if (! is_readable($docPath)) {
        $this->markTestSkipped("Docs not mounted at {$docPath}");
    }

    $markdown = (string) file_get_contents($docPath);
    $lists = substr($markdown, (int) strpos($markdown, '## 3. Permissions from the specification'));
    $lists = substr($lists, 0, (int) strpos($lists, '## 5. Role × permission matrix'));
    preg_match_all('/`([a-z_]+\.[a-z_]+)`/', $lists, $m);

    $config = require dirname(__DIR__, 2).'/config/rbac.php';

    expect(array_values(array_diff(array_unique($m[1]), $config['permissions'])))->toBe([]);
});
