<?php
namespace App\Tests;
use App\TransformEngine;
use PHPUnit\Framework\TestCase;

/**
 * Section G — end-to-end round-trip catalog (G1–G6).
 *
 * Each test exercises the full pipeline in both directions:
 *   xmlParse → emmetEmit  (assert exact abbreviation)
 *   emmetParse → xmlEmit  (assert byte-exact XML reconstruction)
 *
 * Where the engine canonicalises attribute order on emit, the round-tripped
 * XML is asserted against the canonical form rather than the original input,
 * with a comment explaining the difference.
 */
final class RoundTripTest extends TestCase
{
    // G1 — basic nesting with a class, two sibling children with text.
    public function testG1XmlToEmmetToXml(): void
    {
        $xml  = '<div class="a"><h1>Hi</h1><p>Hello</p></div>';
        $abbr = TransformEngine::emmetEmit(TransformEngine::xmlParse($xml));
        $this->assertSame('div.a>h1{Hi}+p{Hello}', $abbr);
        $back = TransformEngine::xmlEmit(TransformEngine::emmetParse($abbr));
        $this->assertSame($xml, $back);
    }

    // G2 — repetition collapsing: three identical children fold to *3.
    public function testG2RepetitionCollapsing(): void
    {
        $xml  = '<ul><li>x</li><li>x</li><li>x</li></ul>';
        $abbr = TransformEngine::emmetEmit(TransformEngine::xmlParse($xml));
        // Engine emits 'ul>li{x}*3' (no wrapper parens: li has text, not children).
        $this->assertSame('ul>li{x}*3', $abbr);
        $back = TransformEngine::xmlEmit(TransformEngine::emmetParse($abbr));
        $this->assertSame($xml, $back);
    }

    // G3 — HTML void elements: <br> and <img> emit without closing tags.
    public function testG3HtmlVoidElements(): void
    {
        $html = '<div><br><img src="x.png"></div>';
        $node = TransformEngine::xmlParse($html, 'html');
        $abbr = TransformEngine::emmetEmit($node);
        $this->assertSame('div>br+img[src="x.png"]', $abbr);
        $back = TransformEngine::xmlEmit(TransformEngine::emmetParse($abbr), 'html');
        $this->assertSame($html, $back);
    }

    // G4 — attribute order: emit canonicalises to id→class→extra-attrs.
    // Input has href before id/class; canonical output reorders to id, class, href.
    public function testG4AttributesPreserveOrder(): void
    {
        $xml  = '<a href="/x" id="y" class="btn"/>';
        $abbr = TransformEngine::emmetEmit(TransformEngine::xmlParse($xml));
        $this->assertSame('a#y.btn[href="/x"]', $abbr);
        // Round-trip produces canonical attribute order (id, class, href),
        // not the original order (href, id, class).
        $canonical = '<a id="y" class="btn" href="/x"/>';
        $back = TransformEngine::xmlEmit(TransformEngine::emmetParse($abbr));
        $this->assertSame($canonical, $back);
    }

    // G5 — assignment example: two items with the same class but different text
    // do NOT collapse to *2 (text differs), so they form a sibling chain.
    public function testG5AssignmentExample(): void
    {
        $xml  = '<ul><li class="item">a</li><li class="item">b</li></ul>';
        $abbr = TransformEngine::emmetEmit(TransformEngine::xmlParse($xml));
        $this->assertSame('ul>li.item{a}+li.item{b}', $abbr);
        $back = TransformEngine::xmlEmit(TransformEngine::emmetParse($abbr));
        $this->assertSame($xml, $back);
    }

    // G6 — nested groups: two identical rows each containing two identical cells
    // collapse to (tr>td*2)*2 with a group wrapper.
    public function testG6NestedGroups(): void
    {
        $xml  = '<table><tr><td/><td/></tr><tr><td/><td/></tr></table>';
        $abbr = TransformEngine::emmetEmit(TransformEngine::xmlParse($xml));
        $this->assertSame('table>(tr>td*2)*2', $abbr);
        $back = TransformEngine::xmlEmit(TransformEngine::emmetParse($abbr));
        $this->assertSame($xml, $back);
    }
}
