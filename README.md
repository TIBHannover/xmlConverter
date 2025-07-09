## Introduction

This plugin converts various XML formats in Open Journal Systems (OJS) and supports interoperability between publication systems that have different XML export and import requirements.

Currently, JATS-XML and TEI-XML are supported, which are used by OJS, Lodel, and Janeway. However, the plugin can easily be extended to support other XML formats.

Both JATS-XML and TEI-XML formats are widely used in publication systems, indexing systems, long-term preservation systems, article production systems, and analysis systems in academia.

The plugin can also be used to plug into the OAI interface for extracting or converting XML.

The original implementation was created by [TIB](https://www.tib.eu) and developed in collaboration with [OpenEdition](https://www.openedition.org/) as part of the EU-fundend [Craft-OA](https://www.craft-oa.eu/) project. [Métopes](https://www.metopes.fr/metopes.html) stylesheets were used to support the conversions.

## Compatible OJS Versions

- [X] 3.3.0
- [X] 3.4.0  (release: *stable-3_4_0*)
- [X] 3.5.0

## Installation

From the root directory of the OJS 3.5.0 release package:

```bash
$ git clone -b stable-3_5_0 https://github.com/TIBHannover/xmlConverter.git plugins/generic/xmlConverter
$ php lib/pkp/tools/installPluginVersion.php plugins/generic/xmlConverter/version.xml
$ npm install
$ npm run dev  #(developments)
$ npm run build #(productions)
```

## Activation

The plugin named **XML Converter Plugin** should be enabled by default. However, if needed, you can activate it manually in your Dashboard:

*Settings > Website > Plugins*

## Contributors

- Jeanette Hatherill, Coalition Publica
- Edith Cannet, IR Métopes
- Marisa Tutt, PKP
- Martin Brändle, University of Zurich
- Dominique Roux, IR Métopes
- João Martins, OpenEdition
- Jean-Christophe Souplet, OpenEdition
- Ipula Ranasinghe, TIB
- Dulip Withanage, TIB
