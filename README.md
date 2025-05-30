#!/bin/bash

# 🎓 IlmAcademie - Quick Setup Script

# Production deployment to smartdalib.work

echo "🎓 IlmAcademie Quick Setup Script"
echo "=================================="
echo "🌐 Target Domain: smartdalib.work"
echo "🚀 Environment: Production"
echo ""

# Colors for beautiful output

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Emojis for status

SUCCESS="✅"
WARNING="⚠️"
ERROR="❌"
INFO="ℹ️"
ROCKET="🚀"
GEAR="⚙️"

print_header() {
echo -e "${BLUE}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║${NC} ${CYAN}$1${NC}${BLUE}"
    echo -e "${BLUE}╚══════════════════════════════════════════════════════════════╝${NC}"
}

print_success() {
echo -e "${GREEN}${SUCCESS}${NC} $1"
}

print_warning() {
echo -e "${YELLOW}${WARNING}${NC} $1"
}

print_error() {
echo -e "${RED}${ERROR}${NC} $1"
}

print_info() {
echo -e "${CYAN}${INFO}${NC} $1"
}

print_step() {
echo -e "${PURPLE}${GEAR}${NC} $1"
}

# Check if we're in the right directory

if [ ! -f "artisan" ]; then
print_error "Laravel artisan file not found!"
print_error "Please run this script from your Laravel project root directory."
exit 1
fi

print_header "🎓 IlmAcademie Production Setup for smartdalib.work"

# Ask for confirmation

print_warning "This will configure your application for PRODUCTION deployment."
print_warning "Make sure you have:"
print_info " • Database credentials ready"
print_info " • SSL certificate installed"
print_info " • Nginx/Apache configured"
print_info " • Domain pointing to this server"
echo ""
read -p "Do you want to continue? (y/N): " -n 1 -r
echo ""
if [[! $REPLY =~ ^[Yy]$]]; then
print_error "Setup cancelled by user."
exit 1
fi

print_header "Step 1: Environment Configuration"

# Backup existing .env

if [ -f ".env" ]; then
print*step "Backing up existing .env file..."
cp .env .env.backup.$(date +%Y%m%d*%H%M%S)
print*success "Backup created: .env.backup.$(date +%Y%m%d*%H%M%S)"
fi

# Create production .env

print_step "Creating production environment configuration..."
cat > .env << 'EOL'
APP_NAME="Ilm Academy"
APP_ENV=production
APP_KEY=base64:p1D2tX6p2AO0MLfkVjWfnoSBbofCvedUhkK4tt7vC2Q=
APP_DEBUG=false
APP_URL=https://smartdalib.work

LOG_CHANNEL=stack
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_FOREIGN_KEYS=true
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=smartdalib_ilmacademie
DB_USERNAME=smartdalib_user
DB_PASSWORD=

BROADCAST_DRIVER=redis
CACHE_DRIVER=redis
FILESYSTEM_DISK=public
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=smtp.smartdalib.work
MAIL_PORT=587
MAIL_USERNAME=noreply@smartdalib.work
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@smartdalib.work"
MAIL_FROM_NAME="Ilm Academy"

VITE_APP_NAME="Ilm Academy"
VITE_APP_URL="https://smartdalib.work"

FORCE_HTTPS=true
SESSION_DOMAIN=.smartdalib.work
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict

VITE_DEV_SERVER_KEY=
VITE_DEV_SERVER_URL=
EOL

print_success "Production .env file created!"
print_warning "Please update database and mail credentials in .env file"

print_header "Step 2: Menu Service Setup"

# Create Services directory

print_step "Creating app/Services directory..."
mkdir -p app/Services

# Create MenuService

print_step "Creating MenuService..."
cat > app/Services/MenuService.php << 'EOL'

<?php

namespace App\Services;

class MenuService
{
    public static function getMenuItems($user)
    {
        if (!$user) {
            return [];
        }

        $menus = [];
        $config = config('menu', []);
        $userRoles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->toArray() : [];

        // Dashboard
        $menus[] = [
            'type' => 'item',
            'title' => 'Dashboard',
            'icon' => 'o-chart-pie',
            'link' => route('dashboard'),
        ];

        // Role-based menus
        if ($user->hasRole('admin')) {
            $menus[] = [
                'type' => 'sub',
                'title' => 'User Management',
                'icon' => 'o-users',
                'children' => [
                    ['title' => 'Users', 'icon' => 'o-user', 'link' => route('admin.users.index')],
                    ['title' => 'Roles', 'icon' => 'o-shield-check', 'link' => route('admin.roles.index')],
                    ['title' => 'Teachers', 'icon' => 'o-academic-cap', 'link' => route('admin.teachers.index')],
                ]
            ];
        }

        return $menus;
    }
}
EOL

print_success "MenuService created!"

print_header "Step 3: Menu Configuration"

# Create menu config
print_step "Creating menu configuration..."
cat > config/menu.php << 'EOL'
<?php

return [
    'enabled_features' => [
        'admin_reports' => env('ENABLE_ADMIN_REPORTS', true),
        'teacher_exams' => env('ENABLE_TEACHER_EXAMS', true),
        'parent_invoices' => env('ENABLE_PARENT_INVOICES', true),
        'notifications' => env('ENABLE_NOTIFICATIONS', true),
    ],

    'icons' => [
        'dashboard' => env('ICON_DASHBOARD', 'o-chart-pie'),
        'users' => env('ICON_USERS', 'o-users'),
        'academic' => env('ICON_ACADEMIC', 'o-book-open'),
        'finance' => env('ICON_FINANCE', 'o-banknotes'),
    ],

    'role_priorities' => [
        'admin' => 1,
        'teacher' => 2,
        'parent' => 3,
        'student' => 4,
    ],
];
EOL

print_success "Menu configuration created!"

print_header "Step 4: Dependencies & Build"

# Install Composer dependencies
print_step "Installing Composer dependencies..."
composer install --optimize-autoloader --no-dev --quiet
print_success "Composer dependencies installed!"

# Install NPM dependencies
print_step "Installing NPM dependencies..."
npm ci --only=production --silent
print_success "NPM dependencies installed!"

# Build assets
print_step "Building production assets..."
npm run build
print_success "Production assets built!"

print_header "Step 5: Laravel Optimization"

# Clear caches
print_step "Clearing application caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
print_success "Caches cleared!"

# Cache for production
print_step "Optimizing for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
print_success "Application optimized!"

# Storage link
print_step "Creating storage symbolic link..."
php artisan storage:link
print_success "Storage link created!"

print_header "Step 6: Database Setup"

print_warning "Ready to run database migrations?"
read -p "Run migrations? (y/N): " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    print_step "Running database migrations..."
    php artisan migrate --force
    print_success "Database migrations completed!"
    
    print_warning "Do you want to seed the database with sample data?"
    read -p "Seed database? (y/N): " -n 1 -r
    echo ""
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        print_step "Seeding database..."
        php artisan db:seed --force
        print_success "Database seeded!"
    fi
else
    print_warning "Skipping database migrations..."
fi

print_header "Step 7: File Permissions"

# Set permissions
print_step "Setting file permissions..."
chmod -R 755 storage bootstrap/cache
if [ "$EUID" -eq 0 ]; then
    chown -R www-data:www-data storage bootstrap/cache
    print_success "Permissions set for www-data!"
else
    print_warning "Run as root to set www-data ownership:"
    print_info "sudo chown -R www-data:www-data storage bootstrap/cache"
fi

print_header "🎉 Setup Complete!"

echo ""
print_success "IlmAcademie has been configured for production!"
print_success "Domain: https://smartdalib.work"
echo ""
print_info "Next steps:"
echo "  1. Update database credentials in .env"
echo "  2. Update mail settings in .env"
echo "  3. Configure your web server (Nginx/Apache)"
echo "  4. Install SSL certificate"
echo "  5. Test the application"
echo ""
print_warning "Important files created/updated:"
echo "  • .env (production configuration)"
echo "  • app/Services/MenuService.php"
echo "  • config/menu.php"
echo "  • Backup: .env.backup.*"
echo ""
print_info "To complete setup:"
echo "  • Edit .env with your database credentials"
echo "  • Configure your web server"
echo "  • Test: https://smartdalib.work"
echo ""
echo -e "${GREEN}${ROCKET}${NC} Happy deploying! ${ROCKET}"
