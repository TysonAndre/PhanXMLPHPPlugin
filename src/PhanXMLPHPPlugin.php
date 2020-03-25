<?php

use Phan\CLI;
use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Element\TypedElement;
use Phan\Language\Element\UnaddressableTypedElement;
use Phan\Language\FileRef;
use Phan\Language\Context;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\PluginV3;
use Phan\PluginV3\BeforeAnalyzeCapability;

/**
 * This plugin checks that PHP elements (class names, etc) referenced by XML files actually exist.
 */
class PhanXMLPHPPlugin extends PluginV3 implements BeforeAnalyzeCapability {
    /** @var string */
    private $xml_dir;

    public function __construct()
    {
        $xml_dir = Config::getValue('plugin_config')['xml_dir'] ?? null;
        if (!$xml_dir) {
            CLI::printErrorToStderr("missing config['plugin_config']['xml_dir'], expected a path to a directory containing XML files\n");
            exit(1);
        }
        if (!is_dir($xml_dir)) {
            CLI::printErrorToStderr("expected config['plugin_config']['xml_dir'] to be a directory, got '$xml_dir'\n");
            exit(1);
        }
        $this->xml_dir = $xml_dir;
    }

    public function beforeAnalyze(CodeBase $code_base): void
    {
        $xml_files = self::directoryNameToFileList($this->xml_dir);
        foreach ($xml_files as $file) {
            $contents = file_get_contents($file);
            if (!$contents) {
                continue;
            }
            $xml = new SimpleXMLElement($contents);
            foreach ($xml->xpath('//class') as $class_node) {
                $this->checkClassNameNode($code_base, $file, $contents, $class_node);
            }
        }
    }

    private function checkClassNameNode(
        CodeBase $code_base,
        string $file,
        string $contents,
        SimpleXMLElement $class_node
    ): void {
        $children = $class_node->children();
        if (count($children) !== 0) {
            $this->emitXMLIssue(
                $contents,
                $class_node,
                $code_base,
                $file,
                'PhanPluginXMLInvalidClass',
                'Invalid <class> node {CODE}, expected a string instead of child nodes, got {CODE} child node(s)',
                [$class_node->asXML(), count($children)]
            );
            return;
        }
        $class_name = $class_node->__toString();
        try {
            $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($class_name);
        } catch (Exception $e) {
            $this->emitXMLIssue(
                $contents,
                $class_node,
                $code_base,
                $file,
                'PhanPluginXMLInvalidClass',
                'Invalid <class> node, expected a valid class name, got {CODE}: {DETAILS}',
                [$class_name, $e->getMessage()]
            );
            return;
        }
        if ($code_base->hasClassWithFQSEN($fqsen)) {
            $context = (new Context())->withFile(FileRef::getProjectRelativePathForPath($file));
            $code_base->getClassByFQSEN($fqsen)->addReference($context);
        } else {
            $this->emitXMLIssue(
                $contents,
                $class_node,
                $code_base,
                $file,
                'PhanPluginXMLUndeclaredClass',
                'Invalid <class> node, could not find class {CLASS}',
                [$fqsen]
            );
        }
    }

    /**
     * Emit an issue if it is not suppressed
     *
     * @param string $contents
     * The raw contents of the file
     *
     * @param SimpleXMLElement $node
     * The XML element where the issue was found.
     *
     * @param CodeBase $code_base
     * The code base in which the issue was found
     *
     * @param string $file
     * The file in which the issue was found
     *
     * @param string $issue_type
     * A name for the type of issue such as 'PhanPluginMyIssue'
     *
     * @param string $issue_message_fmt
     * The complete issue message format string to emit such as
     * 'class with fqsen {CLASS} is broken in some fashion' (preferred)
     * or 'class with fqsen %s is broken in some fashion'
     * The list of placeholders for between braces can be found
     * in \Phan\Issue::UNCOLORED_FORMAT_STRING_FOR_TEMPLATE.
     *
     * @param list<string|int|float|Type|UnionType|FQSEN|TypedElement|UnaddressableTypedElement> $issue_message_args
     * The arguments for this issue format.
     * If this array is empty, $issue_message_args is kept in place
     *
     * @suppress PhanUnusedPrivateMethodParameter TODO: Support suppressions
     */
    private function emitXMLIssue(
        string $contents,
        SimpleXMLElement $node,
        CodeBase $code_base,
        string $file,
        string $issue_type,
        string $issue_message_fmt,
        array $issue_message_args
    ): void {
        $context = (new Context())->withFile(FileRef::getProjectRelativePathForPath($file))
            ->withLineNumberStart(dom_import_simplexml($node)->getLineNo());

        $this->emitIssue(
            $code_base,
            $context,
            $issue_type,
            $issue_message_fmt,
            $issue_message_args
        );
    }

    /**
     * Based on CLI::directoryNameToFileList
     *
     * @param string $directory_name
     * The name of a directory to scan for files ending in `.xml`.
     *
     * @return list<string>
     * A list of PHP files in the given directory
     *
     * @throws InvalidArgumentException
     * if there is nothing to analyze
     */
    private static function directoryNameToFileList(
        string $directory_name
    ): array {
        $file_list = [];

        try {
            $file_extensions = ['xml'];

            if (!\is_array($file_extensions) || count($file_extensions) === 0) {
                throw new InvalidArgumentException(
                    'Empty list in config analyzed_file_extensions. Nothing to analyze.'
                );
            }

            $filter_folder_or_file = /** @param mixed $unused_key */ static function (\SplFileInfo $file_info, $unused_key, \RecursiveIterator $iterator) use ($file_extensions): bool {
                if (\in_array($file_info->getBaseName(), ['.', '..'], true)) {
                    // Exclude '.' and '..'
                    return false;
                }
                if ($file_info->isDir()) {
                    return $iterator->hasChildren();
                }

                if (!in_array(strtolower($file_info->getExtension()), $file_extensions, true)) {
                    return false;
                }
                if (!$file_info->isFile()) {
                    // Handle symlinks to invalid real paths
                    $file_path = $file_info->getRealPath() ?: $file_info->__toString();
                    CLI::printErrorToStderr("Unable to read file $file_path: SplFileInfo->isFile() is false for SplFileInfo->getType() == " . \var_export(@$file_info->getType(), true) . "\n");
                    return false;
                }
                if (!$file_info->isReadable()) {
                    $file_path = $file_info->getRealPath();
                    CLI::printErrorToStderr("Unable to read file $file_path: SplFileInfo->isReadable() is false, getPerms()=" . \sprintf("%o(octal)", @$file_info->getPerms()) . "\n");
                    return false;
                }

                return true;
            };
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator(
                        $directory_name,
                        \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                    ),
                    $filter_folder_or_file
                )
            );

            $file_list = \array_keys(\iterator_to_array($iterator));
        } catch (\Exception $exception) {
            CLI::printWarningToStderr("Caught exception while listing files in '$directory_name': {$exception->getMessage()}\n");
        }

        // Normalize leading './' in paths.
        $normalized_file_list = [];
        foreach ($file_list as $file_path) {
            $file_path = \preg_replace('@^(\.[/\\\\]+)+@', '', (string) $file_path);
            // Treat src/file.php and src//file.php and src\file.php the same way
            $normalized_file_list[\preg_replace("@[/\\\\]+@", "\0", $file_path)] = $file_path;
        }
        \uksort($normalized_file_list, 'strcmp');
        return \array_values($normalized_file_list);
    }
}

return new PhanXMLPHPPlugin();
