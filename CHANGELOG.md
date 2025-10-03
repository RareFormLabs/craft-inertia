# Release Notes for Inertia

## 1.1.0 - 2025-10-03

- Pass `message` variable in responses when passing them via `exit` twig functions

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.9...1.1.0

## 1.0.9 - 2025-09-17

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.8...1.0.9

## 1.0.8 - 2025-09-16

- Exclude drafts, revisions, and bulk saves from element save responses
- Exception handling fix

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.7...1.0.8

## 1.0.7 - 2025-09-12

- Change ‘recentElementSave’ to ‘elementResponse’, add objects to match Craft

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.6...1.0.7

## 1.0.6 - 2025-09-11

### What's Changed

* Organize and add error pages by @chasegiunta in https://github.com/RareFormLabs/craft-inertia/pull/13

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.5...1.0.6

## 1.0.5 - 2025-09-07

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.4...1.0.5

## 1.0.4 - 2025-09-06

**Full Changelog**: https://github.com/RareFormLabs/craft-inertia/compare/1.0.3...1.0.4

## 1.0.1 - 2025-08-02

- Handle potential recursion errors from JSON encoding

## 1.0.0 - 2025-07-27

- Deprecate the `inertia` twig function in favor of independent `prop` & `page` functions, to allow flexible individual prop caching.
- Remove `inertiaShare` twig function, replaced with `prop`.
- Update README to reflect new DX.

## 1.0.0-beta.5 - 2025-04-19

- Fix response error in axios hook

## 1.0.0-beta.4 - 2025-04-14

- Adds Axios hook for CSRF handling & form submission DX improvements

## 1.0.0-beta.3 - 2025-03-26

- Adds auto variable capture functionality

## 1.0.0-beta.2 - 2025-03-04

- Fix routing takeover for non-Craft elements
- Automatically load `entry` & `category` variables when route is matched to those elements. Remove generic `element` variable.

## 1.0.0-beta.1 - 2025-02-22

- Add `pull` tag DX helper for sharing variables across templates.

## 1.0.0-alpha.5 - 2025-02-01

- Fix console error
- Improve error stack trace handling

## 1.0.0-alpha.4 - 2025-01-01

- Shared props are now gathered from all .twig/.html files in `_shared` directory.

## 1.0.0-alpha.3 - 2024-12-31

- Adds Yii debug toolbar functionality.

## 1.0.0-alpha.2 - 2024-12-19

- Adds ‘recentElementSave’ prop, which includes element ID, automatically after element saves.

## 1.0.0-alpha.1 - 2024-12-18

- Initial release
