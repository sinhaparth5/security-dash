<?php

use App\Upstream\Semver;

test('ranges match the versions npm would accept', function (string $range, string $version, bool $expected) {
    expect(Semver::satisfies($version, $range))->toBe($expected);
})->with([
    'caret allows minor and patch' => ['^1.2.3', '1.9.0', true],
    'caret stops at the next major' => ['^1.2.3', '2.0.0', false],
    'caret below the floor' => ['^1.2.3', '1.2.2', false],
    'caret on 0.x pins the minor' => ['^0.2.3', '0.3.0', false],
    'caret on 0.0.x pins the patch' => ['^0.0.3', '0.0.4', false],
    'tilde allows patches' => ['~1.2.3', '1.2.9', true],
    'tilde stops at the next minor' => ['~1.2.3', '1.3.0', false],
    'x-range' => ['1.2.x', '1.2.7', true],
    'partial version is an x-range' => ['1', '1.99.0', true],
    'star matches anything stable' => ['*', '3.0.0', true],
    'empty range matches anything' => ['', '0.0.1', true],
    'comparator set' => ['>=1.2.0 <1.4.0', '1.3.5', true],
    'comparator set upper bound' => ['>=1.2.0 <1.4.0', '1.4.0', false],
    'partial greater-than skips the whole minor' => ['>1.2', '1.2.9', false],
    'partial less-or-equal includes the whole minor' => ['<=1.2', '1.2.9', true],
    'union' => ['^1.0.0 || ^3.0.0', '3.1.0', true],
    'union miss' => ['^1.0.0 || ^3.0.0', '2.1.0', false],
    'hyphen range' => ['1.2.3 - 2.3', '2.3.9', true],
    'hyphen range upper bound' => ['1.2.3 - 2.3', '2.4.0', false],
    'spaced operator' => ['>= 1.2.0', '1.2.0', true],
    'prerelease excluded by a stable range' => ['^1.0.0', '1.5.0-beta.1', false],
    'prerelease allowed when the range names that version' => ['^1.5.0-beta.0', '1.5.0-beta.1', true],
    'prerelease of another version stays excluded' => ['^1.5.0-beta.0', '1.6.0-beta.1', false],
]);

test('ranges npm resolves elsewhere than the registry are unknown', function (string $range) {
    expect(Semver::satisfies('1.0.0', $range))->toBeNull();
})->with(['latest', 'github:acme/lib', 'file:../lib', 'npm:other@^1.0.0', 'workspace:*']);

test('prereleases sort below their release and identifiers compare per semver', function () {
    $sorted = ['1.0.0', '1.0.0-rc.1', '1.0.0-alpha.beta', '1.0.0-alpha', '1.0.0-beta.11', '1.0.0-beta.2', '1.0.0-alpha.1', '0.9.9'];

    usort($sorted, fn (string $a, string $b) => Semver::compare($a, $b));

    expect($sorted)->toBe(['0.9.9', '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0']);
});

test('the highest matching version is the one npm installs', function () {
    expect(Semver::maxSatisfying(['3.3.6', '3.3.8', '3.4.0', '4.0.0', '3.3.9-beta.1'], '^3.3.6'))->toBe('3.4.0')
        ->and(Semver::maxSatisfying(['3.3.6', '3.3.8'], '^4.0.0'))->toBeNull();
});
