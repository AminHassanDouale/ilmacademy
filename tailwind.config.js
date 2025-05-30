/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        // You will probably also need these lines
        "./resources/**/**/*.blade.php",
        "./resources/**/**/*.js",
        "./app/View/Components/**/**/*.php",
        "./app/Livewire/**/**/*.php",

        // Add mary
        "./vendor/robsontenorio/mary/src/View/Components/**/*.php",

        // Laravel built in pagination
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            animation: {
                'fadeIn': 'fadeIn 0.8s ease-in-out',
                'slideUp': 'slideUp 0.6s ease-out',
                'slideRight': 'slideRight 0.6s ease-out',
                'scaleIn': 'scaleIn 0.4s ease-out',
                'bounce-small': 'bounce-small 2s infinite',
                'pulse-slow': 'pulse-slow 3s infinite',
                'shadow-pulse': 'shadow-pulse 2s infinite',
                'border-pulse': 'border-pulse 2s infinite',
                'rotate': 'rotate 6s linear infinite',
            },
            keyframes: {
                fadeIn: {
                    'from': { opacity: '0' },
                    'to': { opacity: '1' }
                },
                slideUp: {
                    'from': { transform: 'translateY(20px)', opacity: '0' },
                    'to': { transform: 'translateY(0)', opacity: '1' }
                },
                slideRight: {
                    'from': { transform: 'translateX(-20px)', opacity: '0' },
                    'to': { transform: 'translateX(0)', opacity: '1' }
                },
                scaleIn: {
                    'from': { transform: 'scale(0)' },
                    'to': { transform: 'scale(1)' }
                },
                'bounce-small': {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-3px)' }
                },
                'pulse-slow': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.8' }
                },
                'shadow-pulse': {
                    '0%, 100%': { boxShadow: '0 0 0 rgba(79, 70, 229, 0.2)' },
                    '50%': { boxShadow: '0 0 15px rgba(79, 70, 229, 0.6)' }
                },
                'border-pulse': {
                    '0%, 100%': { borderColor: 'rgba(79, 70, 229, 0.3)' },
                    '50%': { borderColor: 'rgba(79, 70, 229, 0.8)' }
                },
                rotate: {
                    'from': { transform: 'rotate(0deg)' },
                    'to': { transform: 'rotate(360deg)' }
                },
            },
        },
    },
    safelist: [
        {
            pattern: /badge-|(bg-primary|bg-success|bg-info|bg-error|bg-warning|bg-neutral|bg-purple|bg-yellow)/
        },
        // Add animation classes to safelist to prevent purging
        'animate-fadeIn',
        'animate-slideUp',
        'animate-slideRight',
        'animate-scaleIn',
        'animate-bounce-small',
        'animate-pulse-slow',
        'animate-shadow-pulse',
        'animate-border-pulse',
        'animate-rotate',
        'delay-100',
        'delay-200',
        'delay-300',
        'delay-400',
        'delay-500',
        'pattern-dots',
        'pattern-grid',
        'pattern-diagonal',
        'glass',
        'glass-dark',
        'gradient-text-primary',
        'gradient-text-success',
        'shadow-soft',
        'shadow-soft-lg',
        'hover-scale',
        'hover-lift'
    ],
    // Add daisyUI
    plugins: [require("daisyui")],

    // Change theme primary color
    daisyui: {
        themes: [
            {
                light: {
                    ...require("daisyui/src/theming/themes")["light"],
                    primary: '#10439F'
                },
                dark: {
                    ...require("daisyui/src/theming/themes")["dark"],
                    primary: '#10439F'
                }
            }
        ]
    }
}
