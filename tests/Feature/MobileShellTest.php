<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins the three mobile-shell decisions that phone users notice immediately
 * when they regress, and that nothing else in the suite can see because they
 * live entirely in Vue templates and the root Blade layout:
 *
 * 1. `h-dvh` on the app shells. With `h-screen` (100vh) mobile Chrome sizes the
 *    shell to the viewport *without* its collapsing URL bar, which pushed the
 *    B2C bottom tab bar under the browser chrome — it looked like the menu had
 *    disappeared, and Profile/Logout became unreachable.
 * 2. `viewport-fit=cover`, without which `env(safe-area-inset-*)` resolves to
 *    0 and the same tab bar sits under the iOS home indicator.
 * 3. The admin sidebar being off-canvas below `md`. As a permanent 224px column
 *    it took two thirds of a phone screen and crushed the admin tables into an
 *    unreadable strip.
 *
 * Reading the sources is deliberate: these are single tokens that a careless
 * find-and-replace or a merge can drop without breaking the build.
 */
class MobileShellTest extends TestCase
{
    public function test_the_root_layout_opts_into_the_safe_area_insets(): void
    {
        $layout = (string) file_get_contents(resource_path('views/app.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<meta name="viewport" content="[^"]*viewport-fit=cover[^"]*">/',
            $layout,
            'app.blade.php must keep viewport-fit=cover, or env(safe-area-inset-*) resolves to 0.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shellProvider(): array
    {
        return [
            'customer shell' => ['js/layouts/app/AppSidebarLayout.vue'],
            'admin shell' => ['js/layouts/AdminLayout.vue'],
        ];
    }

    #[DataProvider('shellProvider')]
    public function test_the_app_shells_are_sized_with_the_dynamic_viewport_height(string $component): void
    {
        $source = (string) file_get_contents(resource_path($component));

        // Template comments explain why h-screen was dropped, so only the markup is checked.
        $markup = (string) preg_replace('/<!--.*?-->/s', '', $source);

        $this->assertStringContainsString('h-dvh', $markup, "{$component} must size its shell with h-dvh.");
        $this->assertStringNotContainsString(
            'h-screen',
            $markup,
            "{$component} still uses h-screen; on mobile Chrome that hides the bottom of the shell.",
        );
    }

    public function test_the_customer_tab_bar_keeps_its_labels_and_clears_the_home_indicator(): void
    {
        $source = (string) file_get_contents(resource_path('js/layouts/app/AppSidebarLayout.vue'));

        $this->assertStringContainsString('env(safe-area-inset-bottom)', $source);
        $this->assertStringContainsString('{{ item.label }}', $source, 'Icon-only tabs hid Profile and Logout from users.');
        $this->assertStringContainsString('Abmelden', $source);
    }

    public function test_the_admin_sidebar_is_a_drawer_below_the_md_breakpoint(): void
    {
        $source = (string) file_get_contents(resource_path('js/components/AdminSidebar.vue'));

        $this->assertStringContainsString('-translate-x-full', $source, 'The admin sidebar must slide off-canvas on phones.');
        $this->assertStringContainsString('md:sticky', $source, 'The admin sidebar must still be a column from md up.');
        $this->assertStringContainsString("(e: 'close')", $source, 'The drawer must be dismissable.');
    }

    public function test_the_admin_shell_renders_a_menu_trigger_for_the_drawer(): void
    {
        $source = (string) file_get_contents(resource_path('js/layouts/AdminLayout.vue'));

        $this->assertStringContainsString('aria-label="Menü öffnen"', $source);
        $this->assertStringContainsString('md:hidden', $source, 'The trigger must not show once the sidebar is a column.');
    }
}
