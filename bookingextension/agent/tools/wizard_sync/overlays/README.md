# Booking Wizard (local_wizard)

The Booking Wizard is an AI assistant for Moodle. People describe in their own words and their own
language what they want to do, and the wizard answers or carries it out: it finds content and people,
sets up courses and activities, creates quizzes and quiz questions, builds and shares reports, and
diagnoses access, enrolment and notification problems. Every action runs with the permissions of the
person asking, and nothing is changed before that person has seen a preview and confirmed it.

The plugin runs on any Moodle site. mod_booking is **not** required; when it is installed, the wizard
also works with booking options, booking rules and bookings.

> **Status:** experimental. The wizard is enabled per site and every skill can be switched off
> individually.

## Requirements

| | |
|---|---|
| Moodle | 4.5 to 5.2 (`$plugin->requires` 2024100700) |
| AI subsystem | Moodle's core AI subsystem with at least one configured AI provider |
| AI provider | either Moodle's own OpenAI provider (`aiprovider_openai`) or [aiprovider_wunderbyte](https://github.com/Wunderbyte-GmbH/moodle-aiprovider_wunderbyte) (Moodle 5.0 and later) |
| Optional | mod_booking (booking skills), other plugins that register wizard skills |

## Installation

1. Copy the plugin to `local/wizard` in your Moodle code base (on Moodle 5.1+ with the `public/`
   layout: `public/local/wizard`), or install the ZIP through *Site administration > Plugins >
   Install plugins*.
2. Run the upgrade: `php admin/cli/upgrade.php` or open *Site administration > Notifications*.
3. Enable the wizard under *Site administration > Plugins > Local plugins > Booking Wizard*.

If the bookingextension agent of mod_booking is installed on the same site, local_wizard takes over:
it adopts the agent's data and settings on install, and the bundled agent stands down.

## Connecting an AI provider

The wizard uses the AI provider that is configured in Moodle's AI subsystem. There are three ways to
get there; the wizard's own panel offers the matching one:

- **Free trial.** A user with the capability *Start the AI trial setup* can request a trial with one
  click, after confirming the data-protection notice. The site receives a trial key for the Wunderbyte
  AI gateway (models hosted in European data centres), and the wizard configures the provider: `aiprovider_wunderbyte` when it is installed, otherwise Moodle's own
  OpenAI provider pointed at the Wunderbyte gateway. Each site can use the trial once.
- **Existing Wunderbyte access.** With a key from a Wunderbyte subscription, the wizard configures the
  Wunderbyte provider in one step. An existing instance is updated, not duplicated.
- **Your own provider.** Configure any provider in *Site administration > AI > AI providers* and
  enable the actions *Generate text* (and *Summarise text* on the OpenAI provider). For the best
  results use `aiprovider_wunderbyte`, which also offers embeddings for skill selection and document
  search.

## Opening the wizard

A magic wand in the top navigation bar opens the wizard on every page for users with the capability
*See the global Booking Wizard magic wand*. With mod_booking installed it is also available inside
booking activities.

## Skills

A skill is one capability the wizard can use. The plugin ships these families; each skill can be
switched on or off in the skill governance page and is guarded by its own capability
(`local/wizard:skill_<name>`).

| Family | What it covers |
|---|---|
| `wizard.*` | Memory, documentation questions, finding and listing skills |
| `core.*` | Finding users and site content, diagnosing permissions and notifications |
| `course.*` | Creating courses, adding and updating activities and quizzes, enrolments, course structure |
| `question.*` | Generating quiz questions from text or PDF |
| `report.*` | Report Builder: finding, creating, querying, scheduling and sharing reports |
| `mod_booking.*` | Booking options, rules and bookings - **only when mod_booking is installed** |

Other plugins can register further skill families through hooks. The complete list with risk classes
and key inputs is in [docs/skills/README.md](docs/skills/README.md).

## Capabilities

| Capability | Purpose |
|---|---|
| `local/wizard:useaiinstructions` | Use the wizard |
| `local/wizard:seemagicwand` | See the magic wand in the navigation bar |
| `local/wizard:requesttrial` | Start the AI trial setup |
| `local/wizard:manageaiproviders` | Configure AI provider credentials |
| `local/wizard:confirmforsession` | Suspend action confirmations for the current session |
| `local/wizard:managegovernance` | Switch skills on and off |
| `local/wizard:configuresitesearch` | Configure semantic site search indexing |
| `local/wizard:mcpaccess` | Call wizard skills through the MCP web service |
| `local/wizard:viewdebug` | View debug information in the chat |
| `local/wizard:ignoreaiavailability` | Ignore course and activity AI availability settings |
| `local/wizard:viewbenchmarks`, `runbenchmarks`, `managebenchmarks` | Benchmark reports and runs |
| `local/wizard:skill_<name>` | Use one specific skill |

## Administration pages

Under *Site administration > Plugins > Local plugins > Booking Wizard*: the main settings, skill
governance, site search governance, the skill selection debug tool, and the AI benchmark report and
settings.

The wizard panel links to *Connect with Claude*, a page that checks everything needed to call wizard
skills from an external AI client through the MCP web service.

## Privacy

The setting *AI privacy mode* controls whether personal data is anonymised before a message reaches
the AI provider:

| Mode | Effect |
|---|---|
| Strict (default) | Names and e-mail addresses from the site and those typed by users are replaced by pseudonyms |
| Soft | Only data the wizard resolves from the site is anonymised |
| Off | No anonymisation before the AI provider |

Pseudonyms are resolved back only for display in the user's own chat.

## Documentation

- [Documentation overview](docs/README.md)
- [User guide](docs/user/README.md)
- [Skills catalog](docs/skills/README.md)

## Development

This plugin is generated from the bookingextension agent that ships with mod_booking; that agent is the
single source of truth. Do not edit this repository by hand: changes are made in the agent and the
plugin is regenerated.

## License

GNU GPL v3 or later. © Wunderbyte GmbH, [wunderbyte.at](https://www.wunderbyte.at).
