<?php

declare(strict_types = 1);

namespace AstrX\I18n;

interface TranslatorAwareInterface
{
    public function setTranslator(Translator $translator)
    : void;
}