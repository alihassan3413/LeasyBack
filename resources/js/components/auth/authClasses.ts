/**
 * Class overrides shared by the six auth pages (Login, Register, password
 * reset/confirm, email verification). They restyle the shared ui/* primitives
 * for the auth screens only, so no other page changes.
 *
 * Colours are the existing brand palette only; the opacity steps are what make
 * the pairings pass WCAG AA on white:
 *   - brand-teal/55 control border ≈ 3.3:1 (non-text minimum is 3:1)
 *   - brand-teal text on brand-orange ≈ 4.8:1 (white on orange was 2.6:1)
 *   - brand-teal/70 secondary text ≈ 5.0:1
 * Radius 6px is DESIGN-FUTURE.md's radius-sm.
 */

export const authField =
    'h-10 rounded-[6px] border-brand-teal/55 bg-white px-3 shadow-none focus-visible:border-brand-teal focus-visible:ring-brand-green/40 [&[readonly]]:bg-brand-teal/5 data-[state=open]:border-brand-teal';

export const authButton =
    'h-10 w-full rounded-[6px] bg-brand-orange px-4 text-sm font-bold text-brand-teal shadow-none hover:bg-brand-orange/90 focus-visible:ring-brand-teal';

export const authLink =
    'rounded-[2px] font-semibold text-brand-teal underline decoration-brand-green decoration-2 underline-offset-4 hover:decoration-brand-teal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-teal';

export const authCheckbox =
    'rounded-[4px] border-brand-teal/55 focus-visible:ring-brand-teal data-[state=checked]:border-brand-teal data-[state=checked]:bg-brand-teal data-[state=checked]:text-white';

export const authHint = 'text-xs leading-relaxed text-brand-teal/70';
