PhanXMLPHPPlugin
================

An example plugin to run checks of XML files referencing PHP elements.

For example, when it sees an XML element containing `<class x="y">MyClass</class>`,
it will warn if there is no class of the name `MyClass`, or if it sees something other than a class name.

See [tests/](tests/) for details on how to run this.
