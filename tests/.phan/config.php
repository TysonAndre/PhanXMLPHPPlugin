<?php
return [
    'directory_list' => [
        'src'
    ],
    'plugin_config' => [
        // Default value
        'xml_dir' => dirname(__DIR__) . '/xml',
    ],
    'plugins' => [
        '../src/PhanXMLPHPPlugin.php',
    ],
];
