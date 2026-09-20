<?php

/**
 * @file
 * Twig CS Fixer configuration aligned with Drupal core.
 */

use Drupal\Core\Template\TwigTransTokenParser;
use TwigCsFixer\Config\Config;
use TwigCsFixer\File\Finder;
use TwigCsFixer\Rules\Literal\CompactHashRule;
use TwigCsFixer\Rules\Whitespace\IndentRule;
use TwigCsFixer\Ruleset\Ruleset;
use TwigCsFixer\Standard\TwigCsFixer;

$config = new Config();
// Agency's canonical lint command is read-only and the Twig corpus is small.
$config->setCacheFile(null);
$config->addTokenParser(new TwigTransTokenParser());

$finder = new Finder();
$finder->exclude('tests');
$config->setFinder($finder);

$ruleset = new Ruleset();
$ruleset->addStandard(new TwigCsFixer());
$ruleset->overrideRule(new CompactHashRule(TRUE));
$ruleset->overrideRule(new IndentRule(spaceRatio: 2));

$config->allowNonFixableRules();
$config->setRuleset($ruleset);

return $config;
