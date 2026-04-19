# License Plate Validator

A lightweight, config-driven Python module for validating license plate strings against format rules defined in JSON config files.

## Purpose and Design Decisions

The validator is intentionally decoupled from any specific plate format. Rather than hardcoding rules like "must be 7 characters with 3 letters and 4 digits", all constraints live in external `.cnf` files. This means:

- Adding support for a new country or format requires no code changes, only a new config file.
- The same `validate_plate` function handles every format uniformly.
- Partial constraints are supported: a config that only defines `length` will skip character-type checks entirely, making the module useful even when full format details are unknown.

Config loading is isolated in its own function (`load_config`) so it can be tested and reused independently of the validation logic.

## Usage

```python
from license_plate_validator import validate_plate

# Validate against the classic Brazilian format (ABC1234)
result = validate_plate("ABC1234", "brasil_old.cnf")
print(result)  # True

# Validate against the Mercosul format (ABC1D23)
result = validate_plate("ABC1D23", "brasil_mercosul.cnf")
print(result)  # True

# A plate that violates the constraints
result = validate_plate("AB12345", "brasil_old.cnf")
print(result)  # False
```

## Config File Format

Config files are flat JSON objects with up to three optional keys:

```json
{
  "length": 7,
  "letters": 3,
  "numbers": 4
}
```

| Key | Type | Description |
|---|---|---|
| `length` | integer | Expected total number of characters in the plate |
| `letters` | integer | Expected number of alphabetic characters |
| `numbers` | integer | Expected number of digit characters |

All three keys are optional. When a key is absent, that constraint is simply not enforced. For example, a config containing only `length` will accept any plate of the correct length regardless of how many letters or digits it contains.

The two configs included in this project are:

**`brasil_old.cnf`** — classic Brazilian format (e.g. `ABC1234`):
```json
{ "length": 7, "letters": 3, "numbers": 4 }
```

**`brasil_mercosul.cnf`** — Mercosul format (e.g. `ABC1D23`):
```json
{ "length": 7, "letters": 4, "numbers": 3 }
```

## Running the Test Suite

Run the standalone test file directly with Python's built-in `unittest` runner:

```bash
python -m unittest test_license_plate_validator -v
```

No third-party packages are required. The tests load the actual `.cnf` files from disk, so make sure `brasil_old.cnf` and `brasil_mercosul.cnf` are present in the same directory.

## Running the Notebook

Open and run `license_plate_validator.ipynb` in Jupyter. The notebook is fully self-contained: it defines the module, writes the `.cnf` files to the working directory, and executes the full test suite inline.

```bash
jupyter notebook license_plate_validator.ipynb
```

Then run all cells top to bottom (Kernel → Restart & Run All).

## Assumptions

**Character classification**
Letters are identified with `str.isalpha()` and digits with `str.isdigit()`. Any character that is neither (e.g. hyphens, spaces, dots) does not contribute to either count. Such characters would only affect validation if the `length` constraint is defined.

**Case sensitivity**
The validator is case-insensitive by design. `str.isalpha()` returns `True` for both uppercase and lowercase letters, so `abc1234` and `ABC1234` are treated identically. No normalisation is applied before validation.

**Config file location**
In the standalone module (`license_plate_validator.py`), config files are resolved relative to the module file's own directory using `os.path.dirname(os.path.abspath(__file__))`. In the notebook, they are resolved relative to `os.getcwd()` since notebooks do not have a meaningful `__file__`. In both cases the `.cnf` file must be in the expected directory.

**Unrecognized keys**
Any keys in the config file beyond `length`, `letters`, and `numbers` are silently ignored. The validator only reads the keys it knows about, so extra fields cause no errors and have no effect on the result.

**Config value types**
The values for all keys are assumed to be non-negative integers. No type validation is performed on the config values themselves; passing a non-integer (e.g. a string) would cause a runtime error during comparison.
