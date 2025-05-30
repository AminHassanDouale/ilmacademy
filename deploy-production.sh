#!/bin/bash

echo "🚀 Deploying Ilm Academy to Production (smartdalib.work)"
echo "================================================="

# Exit on any error
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Function to print colored output
print_status() {
    echo -e "${GREEN}✓${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}⚠${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

# Check if we're in the right directory
if [ ! -f "artisan" ]; then
    print_error "artisan file not found. Make sure you're in the Laravel project root."
    exit 1
fi

print_status "Starting production deployment..."

# 1. Put application in maintenance mode
print_status "Putting application in maintenance mode..."
php artisan down --render="errors::503" --refresh=15

# 2. Pull latest changes (if using Git)
if [ -d ".git" ]; then
    print_status "Pulling latest changes from repository..."
    git pull origin main
fi

# 3. Install/Update Composer dependencies (production)
print_status "Installing Composer dependencies (production mode)..."
composer install --optimize-autoloader --no-dev

# 4. Install/Update NPM dependencies
print_status "Installing NPM dependencies..."
npm ci --only=production

# 5. Build assets for production
print_status "Building assets for production..."
npm run build

# 6. Clear and optimize caches
print_status "Clearing application caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear

# 7. Cache configuration, routes, and views for production
print_status "Optimizing application for production..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. Run database migrations (with confirmation)
print_warning "Do you want to run database migrations? (y/N)"
read -r response
if [[ "$response" =~ ^([yY][eE][sS]|[yY])$ ]]; then
    print_status "Running database migrations..."
    php artisan migrate --force
else
    print_warning "Skipping database migrations..."
fi

# 9. Seed database (optional, with confirmation)
print_warning "Do you want to seed the database? (y/N)"
read -r response
if [[ "$response" =~ ^([yY][eE][sS]|[yY])$ ]]; then
    print_status "Seeding database..."
    php artisan db:seed --force
else
    print_warning "Skipping database seeding..."
fi

# 10. Create storage link if it doesn't exist
if [ ! -L "public/storage" ]; then
    print_status "Creating storage symbolic link..."
    php artisan storage:link
fi

# 11. Set proper permissions
print_status "Setting proper file permissions..."
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 12. Clear OpCache if available
if command -v php &> /dev/null; then
    print_status "Clearing OpCache..."
    php artisan opcache:clear 2>/dev/null || print_warning "OpCache clear not available"
fi

# 13. Restart queue workers (if using queues)
print_status "Restarting queue workers..."
php artisan queue:restart

# 14. Bring application back online
print_status "Bringing application back online..."
php artisan up

print_status "🎉 Production deployment completed successfully!"
print_status "Your application is now live at: https://smartdalib.work"

echo ""
echo "📋 Post-deployment checklist:"
echo "  ✓ Test the application in browser"
echo "  ✓ Check error logs: tail -f storage/logs/laravel.log"
echo "  ✓ Monitor performance"
echo "  ✓ Verify SSL certificate"
echo "  ✓ Test user authentication"
echo "  ✓ Verify email functionality"
