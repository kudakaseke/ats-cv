<?php

use App\Services\CvKeywordMatcher;

require __DIR__.'/../vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$matcher = new CvKeywordMatcher;
echo json_encode(array_map(fn ($pair) => ['result' => $matcher->compare($pair[0], $pair[1]), 'terms' => [$matcher->terms($pair[0]), $matcher->terms($pair[1])]], $input), JSON_THROW_ON_ERROR);
