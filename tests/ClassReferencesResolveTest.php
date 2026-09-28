<?php

declare(strict_types=1);

/*************************************************************************************/
/*      This file is part of the Thelia package.                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : dev@thelia.net                                                       */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      For the full copyright and license information, please view the LICENSE.txt  */
/*      file that was distributed with this source code.                             */
/*************************************************************************************/

namespace PayzenEmbedded\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every class a source file names through a static call, a constant or an instantiation
 * resolves, with the imports the file declares. A dropped "use" line is not a syntax error,
 * and only shows up when the line runs.
 */
final class ClassReferencesResolveTest extends TestCase
{
    public function testEveryStaticallyCalledClassResolves(): void
    {
        $root = \dirname(__DIR__);
        $unresolved = [];

        foreach ($this->sourceFiles($root) as $file) {
            $source = (string) file_get_contents($file);

            preg_match('/^namespace\s+([^;]+);/m', $source, $namespaceMatch);
            $namespace = $namespaceMatch[1] ?? '';

            preg_match_all('/^use\s+([^;]+?)(?:\s+as\s+(\w+))?;/m', $source, $useMatches, PREG_SET_ORDER);
            $imports = [];
            foreach ($useMatches as $use) {
                $imports[$use[2] ?? substr((string) strrchr('\\' . $use[1], '\\'), 1)] = $use[1];
            }

            // Static calls and constants (Foo::bar(), Foo::BAR) and instantiations (new Foo(), new Foo).
            // Comments name classes too ("see BackHookManager::onModuleConfigure()"): they are not references.
            $code = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $source);

            preg_match_all('/(?<![\w\\\\$>])([A-Z]\w+)::\w+|\bnew\s+([A-Z]\w+)\b(?!\\\\)/', $code, $matches);
            $calls = [null, array_values(array_filter(array_merge($matches[1], $matches[2])))];

            foreach (array_unique($calls[1]) as $shortName) {
                if (\in_array($shortName, ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $className = $imports[$shortName] ?? ($namespace . '\\' . $shortName);

                if (!$this->resolves($className)) {
                    $unresolved[] = sprintf('%s: %s (%s)', str_replace($root . '/', '', $file), $shortName, $className);
                }
            }
        }

        self::assertSame([], $unresolved, "Unresolved class references:\n" . implode("\n", $unresolved));
    }

    /**
     * Model classes extend Propel classes generated at runtime, which no autoloader knows here:
     * the composer loader is asked where the class lives, without loading it. Anything the
     * loader does not know has to be loadable by other means (an enum of PHP, for instance).
     */
    private function resolves(string $className): bool
    {
        foreach (spl_autoload_functions() as $autoloader) {
            if (\is_array($autoloader) && $autoloader[0] instanceof \Composer\Autoload\ClassLoader) {
                if (false !== $autoloader[0]->findFile($className)) {
                    return true;
                }
            }
        }

        return class_exists($className) || interface_exists($className) || enum_exists($className);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(string $root): array
    {
        $files = [];

        foreach (['Controller', 'Event', 'EventListener', 'Form', 'Hook', 'Loop', 'LyraClient', 'Service'] as $directory) {
            foreach (glob($root . '/' . $directory . '/*.php') ?: [] as $file) {
                $files[] = $file;
            }
        }

        $files[] = $root . '/PayzenEmbedded.php';

        return $files;
    }
}
