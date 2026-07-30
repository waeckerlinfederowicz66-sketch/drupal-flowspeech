# FlowSpeech for Drupal

FlowSpeech for Drupal provides a text-to-speech provider for the Drupal AI
module. It sends text to the public FlowSpeech API and returns a standard WAV
file that can be used by AI Automator and other modules built on Drupal AI.

## Features

- Text-to-speech support through Drupal AI
- 30 voices for narration, education, marketing, and character audio
- API keys stored through the Drupal Key module
- Raw PCM responses converted to playable WAV files
- Drupal 10.2 and Drupal 11 support

## Requirements

- Drupal 10.2 or Drupal 11
- AI module 1.0.5 or later
- Key module 1.18 or later
- A FlowSpeech account and API key

## Installation

Install this module and its dependencies with Composer:

```bash
composer require drupal/flowspeech
drush en flowspeech
```

## Configuration

1. Create a key in the Drupal Key module and store your FlowSpeech API key in
   it.
2. Open **Configuration > AI > Providers > FlowSpeech**.
3. Select the key and save the settings.
4. Choose FlowSpeech as the text-to-speech provider in any compatible Drupal
   AI workflow.

API keys are sent only in the `Authorization` request header. The module does
not log API keys or generated text.

## Usage

Use the provider directly through Drupal AI, or select it in AI Automator to
generate an audio field from a text field. Voice selection is exposed as a
model setting for compatible workflows.

Learn more and create an API key at
[FlowSpeech](https://flowspeech.io/).

## Development

Run the unit tests with:

```bash
composer install
vendor/bin/phpunit
```

## License

GPL-2.0-or-later.
