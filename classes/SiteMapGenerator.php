<?php
class SiteMapGenerator
{
	private ?XMLWriter $xml = null;

	public function __construct()
	{
		$this->xml = new XMLWriter();
		$this->xml->openURI('php://output');
		$this->xml->startDocument('1.0', 'UTF-8');
		$this->xml->setIndent(true);
		$this->xml->startElement('urlset');
		$this->xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
	}

	public function addEntry(string $location, string $lastmod='')
	{
		$this->xml->startElement('url');
		$this->xml->writeElement('loc', $location);
		if ($lastmod != '')
		{
			$this->xml->writeElement('lastmod', $lastmod);
		}
		$this->xml->endElement();
	}

	public function finish()
	{
		$this->xml->endElement();
		$this->xml->endDocument();
	}
}
