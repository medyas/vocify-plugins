<?php
/**
 * Test fixture mutators — helpers to tweak the base fixture in tests.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Set (or clear) a nested fixture value by dot path.
 *
 * @param array  $fixture
 * @param string $path
 * @param mixed  $value
 * @return array
 */
function vocify_test_set(&$fixture, $path, $value)
{
    $segments = explode('.', $path);
    $node = &$fixture;

    foreach ($segments as $segment) {
        if (!isset($node[$segment]) || !is_array($node[$segment])) {
            $node[$segment] = array();
        }
        $node = &$node[$segment];
    }

    $node = $value;

    return $fixture;
}

/**
 * Remove a nested fixture value by dot path.
 *
 * @param array  $fixture
 * @param string $path
 * @return array
 */
function vocify_test_unset(&$fixture, $path)
{
    $segments = explode('.', $path);
    $node = &$fixture;

    while (count($segments) > 1) {
        $segment = array_shift($segments);
        if (!isset($node[$segment]) || !is_array($node[$segment])) {
            return $fixture;
        }
        $node = &$node[$segment];
    }

    unset($node[$segments[0]]);

    return $fixture;
}