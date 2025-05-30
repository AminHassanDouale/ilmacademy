<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run()
    {
        if (Product::count()) {
            return;
        }

        Product::insert([
            [
                'id' => 1,
                'brand_id' => 1,
                'category_id' => 1,
                'name' => 'Macbook Pro 2021',
                'description' => 'The 14-inch MacBook Pro blasts forward with M3, an incredibly advanced chip that brings serious speed and capability. With industry-leading battery life—up to 22 hours—and a beautiful Liquid Retina XDR display, it’s a pro laptop without equal.',
                'price' => '900',
                'cover' => asset('/storage/products/1.jpg')
            ],
            [
                'id' => 2,
                'brand_id' => 1,
                'category_id' => 1,
                'name' => 'Macbook Air 2020',
                'description' => 'Supercharged by the next-generation M2 chip, the redesigned MacBook Air combines incredible performance and up to 18 hours of battery life into its strikingly thin aluminum enclosure.',
                'price' => '600',
                'cover' => asset('/storage/products/2.jpg')
            ],
            [
                'id' => 3,
                'brand_id' => 1,
                'category_id' => 1,
                'name' => 'Macbook Studio 2023',
                'description' => 'Introducing Mac Studio. A remarkably compact powerhouse that fits right on your desk with advanced connectivity for your studio setup. Choose the ferociously fast M1 Max—the most powerful chip ever created for a computer.',
                'price' => '1700',
                'cover' => asset('/storage/products/3.jpg')
            ],
            [
                'id' => 4,
                'brand_id' => 1,
                'category_id' => 2,
                'name' => 'Iphone 12 64GB',
                'description' => '5G speed, A14 Bionic with an edge-to-edge OLED display. Ceramic Shield with four times better drop performance. And Night mode on every camera. iPhone 12 has it all. Incredible color accuracy. A huge jump in pixel density.',
                'price' => '899',
                'cover' => asset('/storage/products/4.jpg')
            ],
            [
                'id' => 5,
                'brand_id' => 1,
                'category_id' => 2,
                'name' => 'Iphone 13 64GB',
                'description' => 'iPhone 13. The most advanced dual-camera system ever on iPhone. Lightning-fast A15 Bionic chip. A big leap in battery life. Durable design. Superfast 5G.¹ And a brighter Super Retina XDR display.',
                'price' => '899',
                'cover' => asset('/storage/products/5.jpg')
            ],
            [
                'id' => 6,
                'brand_id' => 1,
                'category_id' => 2,
                'name' => 'Iphone 14 128GB',
                'description' => 'iPhone 13. The most advanced dual-camera system ever on iPhone. Lightning-fast A15 Bionic chip. A big leap in battery life. Durable design. Superfast 5G.¹ And a brighter Super Retina XDR display.',
                'price' => '999',
                'cover' => asset('/storage/products/6.jpg')
            ],
            [
                'id' => 7,
                'brand_id' => 1,
                'category_id' => 3,
                'name' => 'Homepod mini',
                'description' => 'Jam-packed with innovation, HomePod mini fills the entire room with rich 360-degree audio. Place multiple speakers around the house for a connected sound system.² And with Siri, your favorite do-it-all intelligent assistant.',
                'price' => '99',
                'cover' => asset('/storage/products/7.jpg')
            ],
            [
                'id' => 8,
                'brand_id' => 1,
                'category_id' => 3,
                'name' => 'Homepod',
                'description' => 'The all-new HomePod delivers groundbreaking, premium sound, from clear, detailed highs to deep, rich bass. Advanced computational audio pushes acoustics further. Spatial Audio provides even more immersive sound. Works seamlessly with all your Apple devices. Connect and control your smart home, privately and securely.',
                'price' => '299',
                'cover' => asset('/storage/products/8.jpg')
            ],
            [
                'id' => 9,
                'brand_id' => 2,
                'category_id' => 1,
                'name' => 'Samsung Notebook Intel Core i5',
                'description' => 'Designed to keep its cool without a fan, it features an advanced energy-efficient Qualcomm Snapdragon compute platform that delivers responsive, instant-on performance that draws less power, keeping the battery charged longer — and you in charge of your to-do list.',
                'price' => '766',
                'cover' => asset('/storage/products/9.jpg')
            ],
            [
                'id' => 10,
                'brand_id' => 2,
                'category_id' => 1,
                'name' => 'Samsung Notebook K22 18GB',
                'description' => 'An Intel Celeron processor provides the power to tackle everyday computing tasks, and the 4GB of RAM support smooth multitasking. This Samsung Chromebook 4+ notebook has 64GB of flash memory that accommodates files and documents and offers fast boot times.',
                'price' => '1100',
                'cover' => asset('/storage/products/10.jpg')
            ],
            [
                'id' => 11,
                'brand_id' => 2,
                'category_id' => 1,
                'name' => 'Samsung PAD 2020',
                'description' => 'You love to explore it all, and this PC does it all, with a powerful Intel processor to back it up. Browse inspiration for your next winning recipe, then flip into sketching your next DIY creation with a 360 degree design that’s compatible with S Pen.',
                'price' => '899',
                'cover' => asset('/storage/products/11.jpg')
            ],
            [
                'id' => 12,
                'brand_id' => 2,
                'category_id' => 2,
                'name' => 'Samsung Fold 2021',
                'description' => 'The phone takes you out of the everyday and into the epic. Life doesn’t wait for the perfect lighting, but with Nightography, you are always ready to seize the moment and snap memories like a pro. See your content no matter the time of day on a display with a refresh rate up to 120Hz and Adaptive Vision Booster.',
                'price' => '1100',
                'cover' => asset('/storage/products/12.jpg')
            ],
            [
                'id' => 13,
                'brand_id' => 2,
                'category_id' => 2,
                'name' => 'Samsung Galaxy S20',
                'description' => 'The phone for business that lets you securely connect and collaborate anywhere. Discover new ways to collaborate with an impressive 8K camera and easily go from mobile to desktop to meetings with Samsung DeX. Keep your business data and personal information protected with a secure processor.',
                'price' => '899',
                'cover' => asset('/storage/products/13.jpg')
            ],
            [
                'id' => 14,
                'brand_id' => 2,
                'category_id' => 2,
                'name' => 'Samsung Galaxy S22',
                'description' => 'Beautifully capture the moments that mean the most with an incredibly detail-oriented wide lens. The infinite display makes playing your favorite games an edge-to-edge experience, and, with virtually lag-free 5G and a long-lasting battery, you’ll beat your high score in no time.',
                'price' => '1000',
                'cover' => asset('/storage/products/14.jpg')
            ],
            [
                'id' => 15,
                'brand_id' => 2,
                'category_id' => 3,
                'name' => 'Samsung Level Mini',
                'description' => ' Up to 20 hours of playtime and a handy powerbank to keep your devices charged to keep the party going all night. Rain? Spilled drinks? Beach sand? The IP67 waterproof and dustproof Charge 5 survives whatever comes its way. ',
                'price' => '199',
                'cover' => asset('/storage/products/15.jpg')
            ],
            [
                'id' => 16,
                'brand_id' => 2,
                'category_id' => 3,
                'name' => 'Samsung Radiant Speaker',
                'description' => 'Bring a whole new dimension to any party with the unique dynamic LED lightrings, synced to the powerful sound and deep bass of the PartyBox 110. Take the partybox wherever you go with the splashproof design and plug in a guitar and mic for the ultimate immersive experience.',
                'price' => '299',
                'cover' => asset('/storage/products/16.jpg')
            ],
            [
                'id' => 17,
                'brand_id' => 2,
                'category_id' => 3,
                'name' => 'Samsung Soundbar',
                'description' => 'Think long term. The HP All-in-One PC blends the power of a desktop with the beauty of a slim, modern display into one dependable device designed to grow with you.',
                'price' => '599',
                'cover' => asset('/storage/products/17.jpg')
            ],
            [
                'id' => 18,
                'brand_id' => 3,
                'category_id' => 1,
                'name' => 'LG Notebook G14 ',
                'description' => 'This notebook levels up your go time, camera time, and screen time with the touchscreen display, audio by Bang & Olufsen and HP Presence collaboration technology. And with a sustainable design, serious power, and all the apps you could imagine, your flow is–Unstoppable. Dynamic. Cinematic. Elevate every experience.',
                'price' => '1599',
                'cover' => asset('/storage/products/18.jpg')
            ],
            [
                'id' => 19,
                'brand_id' => 3,
                'category_id' => 1,
                'name' => 'LG Notebook LGram ',
                'description' => 'LG gram Z90R series laptop is an ultra-lightweight powerhouse of productivity that gives you what you need to get it all done…wherever your hustle takes you. The 16”, anti-glare IPS display delivers vivid color and resolution that makes what you see on screen come to life.',
                'price' => '2599',
                'cover' => asset('/storage/products/19.jpg')
            ],
            [
                'id' => 20,
                'brand_id' => 3,
                'category_id' => 1,
                'name' => 'LG PC All-In-One ',
                'description' => 'Think long term. The HP LG-in-One PC blends the power of a desktop with the beauty of a slim, modern display into one dependable device designed to grow with you. ',
                'price' => '3599',
                'cover' => asset('/storage/products/20.jpg')
            ],
            [
                'id' => 21,
                'brand_id' => 3,
                'category_id' => 2,
                'name' => 'LG Smartphone K21',
                'description' => 'Introducing the ultra-modern, ultra-pocketable motorola razr+, which bends over backwards to do things never before possible. Thanks to the largest external display of any flip phone*, you can view more at a glance—and do more with it.',
                'price' => '450',
                'cover' => asset('/storage/products/21.jpg')
            ],
            [
                'id' => 22,
                'brand_id' => 3,
                'category_id' => 2,
                'name' => 'LG Smartphone K22',
                'description' => 'Finally, a phone that keeps up with your imagination. Introducing K22. Make the most of today’s networks with a super-fast Snapdragon 480+ 5G processor.* Capture supersharp photos day or night with an advanced 48MP camera system.',
                'price' => '455',
                'cover' => asset('/storage/products/22.jpg')
            ],
            [
                'id' => 23,
                'brand_id' => 3,
                'category_id' => 2,
                'name' => 'LG Smartphone K23',
                'description' => 'Create at the speed of your imagination with the new moto g stylus 5G featuring a built-in stylus. Connect at lightning-fast speeds with 5G* and a Snapdragon 6 Gen 1 processor. Experience immersive entertainment on a 6.6" 120Hz display.',
                'price' => '460',
                'cover' => asset('/storage/products/23.jpg')
            ],
            [
                'id' => 24,
                'brand_id' => 3,
                'category_id' => 3,
                'name' => 'LG XBOOM',
                'description' => 'Turn it up loud with 100 watts of powerful JBL Pro Sound, synched to a dazzling light show. Access your favorite tunes with Bluetooth, USB, AUX and TWS (True Wireless Stereo) connectivity, grab a friend and sing your hearts out.',
                'price' => '260',
                'cover' => asset('/storage/products/24.jpg')
            ],
            [
                'id' => 25,
                'brand_id' => 3,
                'category_id' => 3,
                'name' => 'LG PK3 Speaker',
                'description' => 'Bring clear, powerful sound to the party with the SRS-XP500 Portable Wireless Speaker. Add extra juice to the hits with MEGA BASS and Sony’s unique X-Balanced Speaker units, and keep the energy going with up to 20 hours of battery life1 plus USB-C quick charging.',
                'price' => '360',
                'cover' => asset('/storage/products/25.jpg')
            ],
            [
                'id' => 26,
                'brand_id' => 3,
                'category_id' => 3,
                'name' => 'LG NP Speaker',
                'description' => 'Immerse yourself in the rhythm of life with unrivalled acoustic precision. Elegantly crafted, the Harman Kardon Onyx Studio 7 offers dual tweeters for beautiful stereo performance and a sleek anodized aluminum handle for ease of portability.',
                'price' => '360',
                'cover' => asset('/storage/products/26.jpg')
            ]
        ]);
    }
}
