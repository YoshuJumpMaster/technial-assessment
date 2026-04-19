# AI Usage Note

## Introduction

I decided here to go with a configurable license plate validator. Given a config file with plate string and constraints, the module will check if constraints are satisfied. Since validation constraints are in the config itself, the module should handle any plate format without any code change, so no validation criteria should be hardcoded.

The implementation decision made was to separate config loading from validation logic into two separate functions, this way, each function has a single, well-defined responsibility and can also be tested independently.

I also decided to contemplate the two types of plates currently in circulation in Brazil as test cases, old plates and Mercosul standard plates. I made this to make the solution more grounded in reality and better illustrate its applicability.

The solution was developed iteratively using Kiro as an AI assistant, with each step reviewed and validated before proceeding to the next. Prompts and workflow process logic are documented below, including reasoning behind each decision and what was accepted, adjusted or notable in each output.

---

## Prompt 1

> "I need to implement a Python module responsible for validating license plates. The module will receive two inputs: the name of a .cnf JSON config file (located in the same directory as the module) and the license plate string to validate. The JSON file may contain up to three attributes: length (total string length), letters (number of letter characters), and numbers (number of digit characters). Not all attributes are guaranteed to be present, so the validation should only enforce the constraints that are actually defined in the file. Write only the module code for now, no tests yet. Make sure the config loading is isolated in its own function, separate from the validation logic itself. Add docstrings explaining the purpose of each function."

**Explanation:**
The first prompt is intended to set the structure for the module only, not setting up any configs or tests yet. The separation between `load_config` and `validate_plate` was deliberate for clearly separating both functions with distinct responsibilities and distinct failure handling. Having the separation of functions clearly defined makes each function independently testable, which was the immediate practical benefit. Kiro proceeded to develop the module exactly as specified. Accepted without modifications.

---

## Prompt 2

> "Now create two .cnf JSON config files to be used for testing the module, representing the two Brazilian license plate formats currently in circulation. The first, brasil_old.cnf, should represent the following format: 7 characters total, 3 letters and 4 numbers (e.g. ABC1234). The second, brasil_mercosul.cnf, should represent the current Mercosul format, also 7 characters total but with 4 letters and 3 numbers (e.g. ABC1D23). Both files should be flat JSON objects using only the length, letters, and numbers keys as defined in the load_config function in license_plate_validator.py"

**Explanation:**
Rather than creating a single generic config, I decided to be more reflective of the reality of vehicles currently in circulation in Brazil and chose to represent both plate formats, making the test more meaningful. A plate valid under one format should correctly fail under the other. Both files were verified by inspecting their contents before proceeding.

---

## Prompt 3

> "Now write the test suite for the license plate validator module using Python's built-in unittest framework. The tests should cover: a valid plate that satisfies all constraints in brasil_old.cnf, a valid plate that satisfies all constraints in brasil_mercosul.cnf, a plate that fails the length constraint, a plate that fails the letters constraint, a plate that fails the numbers constraint, a plate that fails multiple constraints simultaneously, and a case where the config file does not exist. Each test should include a brief comment explaining what specific behavior it is asserting. Do not use any mocking libraries, the tests should load actual .cnf files from disk."

**Explanation:**
For testing, I decided to exclude mocking libraries since config loading from disk is part of the module's intended behavior, hence it's not an external side effect to be isolated. The missing config file case was also included even though the assignment states that files will be there in production but doesn't explicitly say always. Kiro produced all seven specified test cases and ran the tests automatically, confirming all of them passed before I accepted and proceeded.

---

## Prompt 4

> "Now organize everything into a Jupyter notebook with the following structure: a Markdown introduction cell explaining the module's purpose and design decisions, a cell with the full module code, a cell that writes both brasil_old.cnf and brasil_mercosul.cnf to disk programmatically, a cell that runs the full unittest suite and displays the results inline, and a final Markdown cell summarizing the assumptions made. The notebook should be self contained, someone should be able to run it top to bottom and have everything work without any setup beyond having Python and Jupyter installed."

**Explanation:**
I chose to use a notebook to better showcase execution and results. I also asked Kiro to extensively document everything for easier code understanding and future adjustments if needed. I also chose to include a cell to write both configs to disk programmatically in the notebook since this ensures it's truly self-contained and eliminates environment-dependent failure points.

Here it's also worth noting one adaptation Kiro made without being prompted: resolving the config file path via `os.getcwd()` rather than `__file__`, since Jupyter notebooks lack that attribute. Kiro proposed resolving location through `os.getcwd()`, which returns the directory from which the notebook is currently running. Changes made by Kiro were validated by running the notebook locally and confirming 7/7 tests passing in 0.007 seconds.

---

## Prompt 5

> "Now create a README.md for this project containing: the purpose of the module and the design decisions behind putting validation constraints into config files rather than hardcoding them, a use example showing how to call validate_plate directly from Python, the structure and expected format of the .cnf config file, including which keys are optional and what happens when they are absent, how to run the standalone test suite, how to run the notebook, and a section documenting the assumptions made during implementation, specifically around character classification, case sensitivity, config file location resolution, and how unrecognized keys in the config are being handled."

**Explanation:**
With all artifacts in place I prompted Kiro to create README documentation, serving as an entry point for anyone opening the repository and a good place for consolidating the reasoning behind this implementation in one place. Output was reviewed to confirm accuracy and to ensure it reflected the implementation.

---

## Assumptions Summary

Among the assumptions made was the one for character classification using `str.isalpha()` and `str.isdigit()`. These functions were used to count letters and digits respectively. Since `isalpha()` returns `True` for both uppercase and lowercase letters, the validator was constructed under the assumption that it should be case-insensitive, meaning that `abc123` and `ABC123` would satisfy the same constraints. Meanwhile, any character that classifies as neither letter nor digit is silently ignored, only affecting the length constraint validation if defined in the config.

This assumption was made under the understanding that license plates, in practice, shouldn't have any differentiation over upper or lower case. Even though plates are often read only in uppercase, having built-in case insensitivity makes the module more defensive against possible edge cases. If a plate string arrives in lowercase for any reason, the validation integrity is still kept. Normalization could also have been applied through a step such as `plate.upper()` before validation, however, using `isalpha()` handles it implicitly without adding more code.

Another assumption made was that the solution should implement exactly only what the assignment specified, through what the config attributes allow for. Therefore, the decision not to add regex or positional validation was deliberate, since there are no requirements for it. In synthesis, the implementation is faithful to the spec.

The two last assumptions concern config handling. The first is the config file location resolution, which was proposed by Kiro. In the standalone module, config files are resolved relative to the module file itself, using a function that returns the directory where the module lives on disk. However, since a Jupyter notebook was also implemented to showcase execution, and Jupyter notebook files are not regarded as regular Python files, they do not have a `__file__` attribute, meaning the same approach as in regular Python files doesn't work. To accommodate for this, inside the notebook, config file location is resolved through `os.getcwd()`, which returns the directory from which the notebook is currently running.

The last assumption concerns unrecognized keys in the config file. The validator was designed to read only recognized keys (`length`, `letters`, `numbers`) and silently ignore unrecognized ones, also making no attempt to validate the config structure itself. In practice, the config could contain additional fields such as metadata for another system and the validator would simply disregard them and proceed normally. This translates to behavior such that if new keys are introduced in the future, pre-existing config files remain valid and the module continues operational without any code change, which is the right approach for a module designed around an externally defined and potentially evolving config format.
