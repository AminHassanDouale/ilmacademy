<?php

namespace App\Services;

class MenuService
{
    /**
     * Get menu items based on user role and environment configuration
     */
    public static function getMenuItems($user)
    {
        if (!$user) {
            return [];
        }

        $menus = [];

        // Dashboard - available to all
        $menus[] = [
            'type' => 'item',
            'title' => 'Dashboard',
            'icon' => 'o-chart-pie',
            'link' => '/dashboard',
            'roles' => ['admin', 'teacher', 'parent', 'student']
        ];

        // Admin menus
        if ($user->hasRole('admin')) {
            $menus[] = [
                'type' => 'sub',
                'title' => 'User Management',
                'icon' => 'o-users',
                'children' => [
                    ['title' => 'Users', 'icon' => 'o-user', 'link' => '/admin/users'],
                    ['title' => 'Roles', 'icon' => 'o-shield-check', 'link' => '/admin/roles'],
                    ['title' => 'Teachers', 'icon' => 'o-academic-cap', 'link' => '/admin/teachers'],
                    ['title' => 'Parents', 'icon' => 'o-heart', 'link' => '/admin/parents'],
                    ['title' => 'Children', 'icon' => 'o-face-smile', 'link' => '/admin/children'],
                ]
            ];

            $menus[] = [
                'type' => 'sub',
                'title' => 'Academic Management',
                'icon' => 'o-book-open',
                'children' => [
                    ['title' => 'Curricula', 'icon' => 'o-document-text', 'link' => '/admin/curricula'],
                    ['title' => 'Subjects', 'icon' => 'o-squares-2x2', 'link' => '/admin/subjects'],
                    ['title' => 'Academic Years', 'icon' => 'o-calendar-days', 'link' => '/admin/academic-years'],
                    ['title' => 'Timetable', 'icon' => 'o-clock', 'link' => '/admin/timetable'],
                ]
            ];

            $menus[] = [
                'type' => 'sub',
                'title' => 'Finance & Enrollments',
                'icon' => 'o-banknotes',
                'children' => [
                    ['title' => 'Enrollments', 'icon' => 'o-user-plus', 'link' => '/admin/enrollments'],
                    ['title' => 'Payment Plans', 'icon' => 'o-credit-card', 'link' => '/admin/payment-plans'],
                    ['title' => 'Invoices', 'icon' => 'o-document-currency-dollar', 'link' => '/admin/invoices'],
                ]
            ];

            $menus[] = [
                'type' => 'sub',
                'title' => 'Reports & Analytics',
                'icon' => 'o-chart-bar',
                'children' => [
                    ['title' => 'Student Reports', 'icon' => 'o-user-group', 'link' => '/admin/reports/students'],
                    ['title' => 'Attendance Reports', 'icon' => 'o-check-circle', 'link' => '/admin/reports/attendance'],
                    ['title' => 'Exam Reports', 'icon' => 'o-document-check', 'link' => '/admin/reports/exams'],
                    ['title' => 'Financial Reports', 'icon' => 'o-currency-dollar', 'link' => '/admin/reports/finances'],
                ]
            ];

            $menus[] = [
                'type' => 'item',
                'title' => 'Activity Logs',
                'icon' => 'o-eye',
                'link' => '/admin/activity-logs'
            ];
        }

        // Teacher menus
        if ($user->hasRole('teacher')) {
            $menus[] = [
                'type' => 'sub',
                'title' => 'Teaching',
                'icon' => 'o-academic-cap',
                'children' => [
                    ['title' => 'My Subjects', 'icon' => 'o-book-open', 'link' => '/teacher/subjects'],
                    ['title' => 'Sessions', 'icon' => 'o-presentation-chart-bar', 'link' => '/teacher/sessions'],
                    ['title' => 'Take Attendance', 'icon' => 'o-check-circle', 'link' => '/teacher/attendance'],
                    ['title' => 'Exams', 'icon' => 'o-document-check', 'link' => '/teacher/exams'],
                ]
            ];

            $menus[] = [
                'type' => 'item',
                'title' => 'My Profile',
                'icon' => 'o-user-circle',
                'link' => '/teacher/profile'
            ];
        }

        // Parent menus
        if ($user->hasRole('parent')) {
            $menus[] = [
                'type' => 'sub',
                'title' => 'My Children',
                'icon' => 'o-heart',
                'children' => [
                    ['title' => 'Children List', 'icon' => 'o-face-smile', 'link' => '/parent/children'],
                    ['title' => 'Add Child', 'icon' => 'o-plus-circle', 'link' => '/parent/children/create'],
                ]
            ];

            $menus[] = [
                'type' => 'sub',
                'title' => 'Academic',
                'icon' => 'o-book-open',
                'children' => [
                    ['title' => 'Enrollments', 'icon' => 'o-user-plus', 'link' => '/parent/enrollments'],
                    ['title' => 'Attendance', 'icon' => 'o-check-circle', 'link' => '/parent/attendance'],
                    ['title' => 'Exams', 'icon' => 'o-document-check', 'link' => '/parent/exams'],
                ]
            ];

            $menus[] = [
                'type' => 'item',
                'title' => 'Invoices',
                'icon' => 'o-document-currency-dollar',
                'link' => '/parent/invoices'
            ];
        }

        // Student menus
        if ($user->hasRole('student')) {
            $menus[] = [
                'type' => 'sub',
                'title' => 'My Studies',
                'icon' => 'o-book-open',
                'children' => [
                    ['title' => 'My Enrollments', 'icon' => 'o-user-plus', 'link' => '/student/enrollments'],
                    ['title' => 'Sessions', 'icon' => 'o-presentation-chart-bar', 'link' => '/student/sessions'],
                    ['title' => 'My Exams', 'icon' => 'o-document-check', 'link' => '/student/exams'],
                ]
            ];

            $menus[] = [
                'type' => 'item',
                'title' => 'My Invoices',
                'icon' => 'o-document-currency-dollar',
                'link' => '/student/invoices'
            ];

            $menus[] = [
                'type' => 'item',
                'title' => 'My Profile',
                'icon' => 'o-user-circle',
                'link' => '/student/profile'
            ];
        }

        // Common items for all authenticated users
        $menus[] = ['type' => 'separator'];

        $menus[] = [
            'type' => 'item',
            'title' => 'Calendar',
            'icon' => 'o-calendar',
            'link' => '/calendar'
        ];

        $menus[] = [
            'type' => 'item',
            'title' => 'Notifications',
            'icon' => 'o-bell',
            'link' => '/notifications'
        ];

        $menus[] = [
            'type' => 'item',
            'title' => 'Profile Settings',
            'icon' => 'o-cog-6-tooth',
            'link' => '/profile'
        ];

        return $menus;
    }

    /**
     * Check if menu should be shown based on environment config
     */
    public static function shouldShowMenu($menuKey)
    {
        // You can add environment-based menu visibility here
        $hiddenMenus = config('app.hidden_menus', []);

        return !in_array($menuKey, $hiddenMenus);
    }

    /**
     * Get menu order from config
     */
    public static function getMenuOrder()
    {
        return config('app.menu_order', [
            'dashboard',
            'admin',
            'teacher',
            'parent',
            'student',
            'common'
        ]);
    }
}