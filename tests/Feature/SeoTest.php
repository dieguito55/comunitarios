<?php

namespace Tests\Feature;

use Tests\TestCase;

class SeoTest extends TestCase
{
    public function test_the_homepage_exposes_complete_search_metadata(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('<title>Comunitarios | Fundación Territorial de Puno, Perú</title>', false)
            ->assertSee('<link rel="canonical" href="https://comunitarios.org/">', false)
            ->assertSee('Comunitarios es la Fundación Territorial de Puno', false)
            ->assertSee('application/ld+json', false);

        preg_match(
            '/<script type="application\/ld\+json">(.*?)<\/script>/s',
            $response->getContent(),
            $matches,
        );

        $this->assertCount(2, $matches);

        $structuredData = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('https://schema.org', $structuredData['@context']);
        $this->assertSame('WebSite', $structuredData['@graph'][0]['@type']);
        $this->assertSame('Comunitarios', $structuredData['@graph'][0]['name']);
        $this->assertContains('NGO', $structuredData['@graph'][1]['@type']);
        $this->assertContains('Fundación Territorial de Puno', $structuredData['@graph'][1]['alternateName']);
    }

    public function test_robots_and_sitemap_expose_the_canonical_homepage(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));
        $sitemap = simplexml_load_file(public_path('sitemap.xml'));

        $this->assertStringContainsString('Allow: /', $robots);
        $this->assertStringContainsString('Sitemap: https://comunitarios.org/sitemap.xml', $robots);
        $this->assertSame('https://comunitarios.org/', (string) $sitemap->url->loc);
    }
}
