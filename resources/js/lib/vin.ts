/**
 * A FIN (Fahrzeug-Identifizierungsnummer) is exactly 17 characters, letters and
 * digits only — the same length `VehicleRules` validates server-side.
 */
export const VIN_LENGTH = 17;

export function sanitizeVin(value: string | number): string {
    return String(value)
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, VIN_LENGTH);
}
