"""Unit tests for the license_plate_validator module."""

import unittest

from license_plate_validator import validate_plate


class TestLicensePlateValidator(unittest.TestCase):

    def test_valid_old_format(self):
        # A classic Brazilian plate (3 letters + 4 digits) should pass brasil_old.cnf
        self.assertTrue(validate_plate("ABC1234", "brasil_old.cnf"))

    def test_valid_mercosul_format(self):
        # A Mercosul plate (4 letters + 3 digits, mixed) should pass brasil_mercosul.cnf
        self.assertTrue(validate_plate("ABC1D23", "brasil_mercosul.cnf"))

    def test_fails_length_constraint(self):
        # A plate with fewer than 7 characters should fail the length constraint
        self.assertFalse(validate_plate("ABC123", "brasil_old.cnf"))

    def test_fails_letters_constraint(self):
        # A 7-char plate with only 2 letters should fail the letters constraint (expects 3)
        self.assertFalse(validate_plate("AB12345", "brasil_old.cnf"))

    def test_fails_numbers_constraint(self):
        # A 7-char plate with only 3 digits should fail the numbers constraint (expects 4)
        self.assertFalse(validate_plate("ABCD123", "brasil_old.cnf"))

    def test_fails_multiple_constraints(self):
        # A short all-letter plate violates length, numbers, and letters simultaneously
        self.assertFalse(validate_plate("ABC", "brasil_old.cnf"))

    def test_config_file_not_found(self):
        # A non-existent config file should raise FileNotFoundError
        with self.assertRaises(FileNotFoundError):
            validate_plate("ABC1234", "nonexistent.cnf")


if __name__ == "__main__":
    unittest.main()
