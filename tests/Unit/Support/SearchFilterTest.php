<?php

namespace Tests\Unit\Support;

use App\Models\Record;
use App\Support\SearchFilter;
use Tests\TestCase;

/**
 * Tests for the shared index search helper: the `|` multi-token convention
 * and the value normalisation that keeps a comma, or an array-shaped filter value,
 * from crashing the index pages.
 */
class SearchFilterTest extends TestCase
{
    public function test_term_passes_a_plain_string_through_untouched(): void
    {
        $this->assertSame('power, corruption', SearchFilter::term('power, corruption'));
    }

    public function test_term_keeps_null(): void
    {
        $this->assertNull(SearchFilter::term(null));
    }

    public function test_term_joins_an_array_with_the_token_separator(): void
    {
        $this->assertSame('power|corruption', SearchFilter::term(['power', 'corruption']));
    }

    public function test_term_flattens_nested_arrays_and_drops_empty_parts(): void
    {
        $this->assertSame('power|corruption', SearchFilter::term(['power', ['', ' corruption ', null]]));
    }

    public function test_term_returns_null_for_an_array_with_nothing_usable(): void
    {
        $this->assertNull(SearchFilter::term(['', ' ', null]));
    }

    public function test_a_comma_stays_a_single_token(): void
    {
        $query = SearchFilter::apply(Record::query(), 'power, corruption', ['title']);

        $this->assertSame(['%power, corruption%'], $query->getBindings());
    }

    public function test_a_pipe_still_splits_into_and_tokens(): void
    {
        $query = SearchFilter::apply(Record::query(), 'biophilia|part two', ['title', 'barcode']);

        $this->assertSame(
            ['%biophilia%', '%biophilia%', '%part two%', '%part two%'],
            $query->getBindings()
        );
    }

    public function test_an_array_value_does_not_throw_and_behaves_like_the_piped_equivalent(): void
    {
        $fromArray = SearchFilter::apply(Record::query(), ['biophilia', 'part two'], ['title']);
        $fromString = SearchFilter::apply(Record::query(), 'biophilia|part two', ['title']);

        $this->assertSame($fromString->getBindings(), $fromArray->getBindings());
    }

    public function test_a_null_value_leaves_the_query_untouched(): void
    {
        $query = SearchFilter::apply(Record::query(), null, ['title']);

        $this->assertSame([], $query->getBindings());
    }
}
