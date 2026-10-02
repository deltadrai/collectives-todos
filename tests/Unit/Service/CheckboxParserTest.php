<?php

namespace OCA\CollectiveTodos\Tests\Unit\Service;

use OCA\CollectiveTodos\Service\CheckboxParser;
use PHPUnit\Framework\TestCase;

class CheckboxParserTest extends TestCase
{
    private CheckboxParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CheckboxParser();
    }

    public function testParseEmptyContent(): void
    {
        $result = $this->parser->parse('');
        $this->assertEmpty($result);
    }

    public function testParseNoCheckboxes(): void
    {
        $content = "This is just regular text\nwith multiple lines\nbut no checkboxes";
        $result = $this->parser->parse($content);
        $this->assertEmpty($result);
    }

    public function testParseUncheckedCheckbox(): void
    {
        $content = "- [ ] Buy milk";
        $result = $this->parser->parse($content);
        
        $this->assertCount(1, $result);
        $this->assertEquals('Buy milk', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        $this->assertEquals(1, $result[0]['line']);
        $this->assertEquals('- [ ] Buy milk', $result[0]['raw']);
    }

    public function testParseCheckedCheckbox(): void
    {
        $content = "- [x] Buy milk";
        $result = $this->parser->parse($content);
        
        $this->assertCount(1, $result);
        $this->assertEquals('Buy milk', $result[0]['text']);
        $this->assertTrue($result[0]['checked']);
        $this->assertEquals(1, $result[0]['line']);
        $this->assertEquals('- [x] Buy milk', $result[0]['raw']);
    }

    public function testParseCheckedCheckboxUppercaseX(): void
    {
        $content = "- [X] Buy milk";
        $result = $this->parser->parse($content);
        
        $this->assertCount(1, $result);
        $this->assertEquals('Buy milk', $result[0]['text']);
        $this->assertTrue($result[0]['checked']);
        $this->assertEquals(1, $result[0]['line']);
        $this->assertEquals('- [X] Buy milk', $result[0]['raw']);
    }

    public function testParseMultipleCheckboxes(): void
    {
        $content = "- [ ] Task 1\n- [x] Task 2\n- [ ] Task 3";
        $result = $this->parser->parse($content);
        
        $this->assertCount(3, $result);
        
        $this->assertEquals('Task 1', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        $this->assertEquals(1, $result[0]['line']);
        
        $this->assertEquals('Task 2', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
        $this->assertEquals(2, $result[1]['line']);
        
        $this->assertEquals('Task 3', $result[2]['text']);
        $this->assertFalse($result[2]['checked']);
        $this->assertEquals(3, $result[2]['line']);
    }

    public function testParseIndentedCheckboxes(): void
    {
        $content = "  - [ ] Indented task\n    - [x] More indented task";
        $result = $this->parser->parse($content);
        
        $this->assertCount(2, $result);
        
        $this->assertEquals('Indented task', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        $this->assertEquals(1, $result[0]['line']);
        $this->assertEquals('  - [ ] Indented task', $result[0]['raw']);
        
        $this->assertEquals('More indented task', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
        $this->assertEquals(2, $result[1]['line']);
        $this->assertEquals('    - [x] More indented task', $result[1]['raw']);
    }

    public function testParseAlternativeMarkers(): void
    {
        $content = "* [ ] Star marker\n+ [x] Plus marker\n1. [ ] Number marker";
        $result = $this->parser->parse($content);
        
        $this->assertCount(3, $result);
        
        $this->assertEquals('Star marker', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        $this->assertEquals('* [ ] Star marker', $result[0]['raw']);
        
        $this->assertEquals('Plus marker', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
        $this->assertEquals('+ [x] Plus marker', $result[1]['raw']);
        
        $this->assertEquals('Number marker', $result[2]['text']);
        $this->assertFalse($result[2]['checked']);
        $this->assertEquals('1. [ ] Number marker', $result[2]['raw']);
    }

    public function testParseUnicodeContent(): void
    {
        $content = "- [ ] café\n- [x] 日本語\n- [ ] émojis 🎉🎊";
        $result = $this->parser->parse($content);
        
        $this->assertCount(3, $result);
        
        $this->assertEquals('café', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        
        $this->assertEquals('日本語', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
        
        $this->assertEquals('émojis 🎉🎊', $result[2]['text']);
        $this->assertFalse($result[2]['checked']);
    }

    public function testParseMixedContentWithNonCheckboxLines(): void
    {
        $content = "# Todo List\n\n- [ ] First task\nSome regular text\n- [x] Second task\n\nMore text";
        $result = $this->parser->parse($content);
        
        $this->assertCount(2, $result);
        
        $this->assertEquals('First task', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        $this->assertEquals(3, $result[0]['line']);
        
        $this->assertEquals('Second task', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
        $this->assertEquals(5, $result[1]['line']);
    }

    public function testParseCheckboxesWithExtraSpaces(): void
    {
        $content = "- [ ]   Task with extra spaces\n- [x]    Another task";
        $result = $this->parser->parse($content);

        $this->assertCount(2, $result);

        $this->assertEquals('Task with extra spaces', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);

        $this->assertEquals('Another task', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
    }

    public function testParseTrimsTrailingWhitespace(): void
    {
        // The Text editor strips trailing whitespace on save; parsed texts
        // must not depend on it, or the Todos page no longer matches the cache
        $result = $this->parser->parse("- [ ] Projekt \n- [x] Task \r\n");

        $this->assertCount(2, $result);
        $this->assertEquals('Projekt', $result[0]['text']);
        $this->assertEquals('Task', $result[1]['text']);
    }

    public function testParseNestedNumberedList(): void
    {
        $content = "1. [ ] First item\n2. [x] Second item\n3. [ ] Third item";
        $result = $this->parser->parse($content);
        
        $this->assertCount(3, $result);
        
        $this->assertEquals('First item', $result[0]['text']);
        $this->assertFalse($result[0]['checked']);
        $this->assertEquals('1. [ ] First item', $result[0]['raw']);
        
        $this->assertEquals('Second item', $result[1]['text']);
        $this->assertTrue($result[1]['checked']);
        $this->assertEquals('2. [x] Second item', $result[1]['raw']);
        
        $this->assertEquals('Third item', $result[2]['text']);
        $this->assertFalse($result[2]['checked']);
        $this->assertEquals('3. [ ] Third item', $result[2]['raw']);
    }
}