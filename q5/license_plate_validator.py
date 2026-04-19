"""Module for validating license plates against configurable constraints."""

import json
import os


def load_config(config_filename: str) -> dict:
    """Load and return the validation config from a JSON .cnf file.

    The config file must be located in the same directory as this module.
    It may contain any combination of the following keys:
        - length (int): expected total length of the license plate
        - letters (int): expected number of letter characters
        - numbers (int): expected number of digit characters

    Args:
        config_filename: Name of the .cnf JSON file (e.g. 'rules.cnf').

    Returns:
        A dict with the parsed config. Missing keys are simply absent.

    Raises:
        FileNotFoundError: If the config file does not exist.
        ValueError: If the file content is not valid JSON.
    """
    module_dir = os.path.dirname(os.path.abspath(__file__))
    config_path = os.path.join(module_dir, config_filename)

    try:
        with open(config_path, "r", encoding="utf-8") as f:
            return json.load(f)
    except json.JSONDecodeError as e:
        raise ValueError(f"Invalid JSON in config file '{config_filename}': {e}") from e


def validate_plate(plate: str, config_filename: str) -> bool:
    """Validate a license plate string against the rules defined in a config file.

    Only the constraints present in the config are enforced. For example, if
    the config only defines 'length', the character-type counts are not checked.

    Args:
        plate: The license plate string to validate.
        config_filename: Name of the .cnf JSON config file to load rules from.

    Returns:
        True if the plate satisfies all defined constraints, False otherwise.
    """
    config = load_config(config_filename)

    if "length" in config and len(plate) != config["length"]:
        return False

    if "letters" in config and sum(c.isalpha() for c in plate) != config["letters"]:
        return False

    if "numbers" in config and sum(c.isdigit() for c in plate) != config["numbers"]:
        return False

    return True
