export type Restaurant = {
  id: string;
  slug: string;
  name: string;
  cuisine: string;
  description: string;
  rating: number;
  reviewCount: number;
  deliveryMinutes: number;
  deliveryFeeCents: number;
  minOrderCents: number;
  isOpen: boolean;
  isFeatured: boolean;
  offerLabel?: string;
  image: string;
  logo: string;
  address: string;
  commissionRate: number;
  isPlatformRestaurant?: boolean;
  isFeaturedPartner?: boolean;
};

export type MenuItem = {
  id: string;
  restaurantId: string;
  restaurantSlug: string;
  category: string;
  name: string;
  description: string;
  priceCents: number;
  image: string;
  isVeg?: boolean;
  allergens?: string[];
  popular?: boolean;
};

export type Offer = {
  id: string;
  title: string;
  description: string;
  restaurantName: string;
  badge: string;
  image: string;
};

export type OrderRow = {
  id: string;
  orderNumber: string;
  customerName: string;
  restaurantName: string;
  status: string;
  totalCents: number;
  fulfilment: "Delivery" | "Pickup";
  placedAt: string;
};

export type Settlement = {
  id: string;
  restaurantName: string;
  period: string;
  grossCents: number;
  commissionCents: number;
  netCents: number;
  status: "Pending" | "Paid";
};

export const cuisines = [
  "Nepali",
  "Indian",
  "Grill",
  "Momo",
  "Seafood",
  "Vegetarian",
  "Cafe",
  "Dessert",
];

export const restaurants: Restaurant[] = [
  {
    id: "r1",
    slug: "himalayan-kitchen",
    name: "Himalayan Kitchen",
    cuisine: "Nepali",
    description:
      "Mountain-inspired thalis, smoky tandoor breads and slow-cooked dals from a Kathmandu family kitchen.",
    rating: 4.8,
    reviewCount: 312,
    deliveryMinutes: 28,
    deliveryFeeCents: 199,
    minOrderCents: 1200,
    isOpen: true,
    isFeatured: true,
    offerLabel: "15% off lunch",
    image:
      "https://images.unsplash.com/photo-1585937421612-70a008356fbe?auto=format&fit=crop&w=1200&q=80",
    logo:
      "https://images.unsplash.com/photo-1414235077428-338989a2e8c0?auto=format&fit=crop&w=200&q=80",
    address: "14 Thamel Lane, Kathmandu",
    commissionRate: 12,
  },
  {
    id: "r2",
    slug: "spice-garden",
    name: "Spice Garden",
    cuisine: "Indian",
    description:
      "Fragrant curries, charcoal-grilled kebabs and house-made chutneys with generous spice and warmth.",
    rating: 4.7,
    reviewCount: 248,
    deliveryMinutes: 32,
    deliveryFeeCents: 249,
    minOrderCents: 1500,
    isOpen: true,
    isFeatured: true,
    offerLabel: "Free delivery",
    image:
      "https://images.unsplash.com/photo-1588168333986-5078d3ae3976?auto=format&fit=crop&w=1200&q=80",
    logo:
      "https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=200&q=80",
    address: "88 Durbar Marg",
    commissionRate: 14,
  },
  {
    id: "r3",
    slug: "urban-grill",
    name: "Urban Grill",
    cuisine: "Grill",
    description:
      "Flame-kissed steaks, craft burgers and roasted vegetables for evenings that deserve a proper grill.",
    rating: 4.6,
    reviewCount: 189,
    deliveryMinutes: 35,
    deliveryFeeCents: 299,
    minOrderCents: 1800,
    isOpen: true,
    isFeatured: false,
    image:
      "https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=1200&q=80",
    logo:
      "https://images.unsplash.com/photo-1466978913421-dad2ebd01d17?auto=format&fit=crop&w=200&q=80",
    address: "2 Lazimpat Road",
    commissionRate: 11,
  },
  {
    id: "r4",
    slug: "kathmandu-momo-house",
    name: "Kathmandu Momo House",
    cuisine: "Momo",
    description:
      "Hand-folded momos, fiery achar and steaming jhol for late nights and weekend cravings.",
    rating: 4.9,
    reviewCount: 521,
    deliveryMinutes: 22,
    deliveryFeeCents: 149,
    minOrderCents: 800,
    isOpen: true,
    isFeatured: true,
    offerLabel: "Buy 2 get 1",
    image:
      "https://images.unsplash.com/photo-1534422298391-e4f8c172dddb?auto=format&fit=crop&w=1200&q=80",
    logo:
      "https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=200&q=80",
    address: "Basantapur Square",
    commissionRate: 13,
  },
  {
    id: "r5",
    slug: "coastal-curry",
    name: "Coastal Curry",
    cuisine: "Seafood",
    description:
      "Coconut-forward seafood curries, crisp prawn fritters and citrus salads from the southern coast.",
    rating: 4.5,
    reviewCount: 164,
    deliveryMinutes: 40,
    deliveryFeeCents: 349,
    minOrderCents: 2000,
    isOpen: false,
    isFeatured: false,
    image:
      "https://images.unsplash.com/photo-1565299624946-b28f40a0ae38?auto=format&fit=crop&w=1200&q=80",
    logo:
      "https://images.unsplash.com/photo-1559339352-11d035aa65de?auto=format&fit=crop&w=200&q=80",
    address: "Patan Riverside",
    commissionRate: 12,
  },
  {
    id: "r6",
    slug: "green-table",
    name: "Green Table",
    cuisine: "Vegetarian",
    description:
      "Seasonal vegetarian plates, grain bowls and bright salads prepared with market-fresh produce.",
    rating: 4.7,
    reviewCount: 203,
    deliveryMinutes: 26,
    deliveryFeeCents: 199,
    minOrderCents: 1000,
    isOpen: true,
    isFeatured: true,
    offerLabel: "New season menu",
    image:
      "https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=1200&q=80",
    logo:
      "https://images.unsplash.com/photo-1490818387583-1baba5e638af?auto=format&fit=crop&w=200&q=80",
    address: "Jhamsikhel Green",
    commissionRate: 10,
  },
];

export const menuItems: MenuItem[] = [
  {
    id: "m1",
    restaurantId: "r1",
    restaurantSlug: "himalayan-kitchen",
    category: "Thali",
    name: "Himalayan Dal Bhat Thali",
    description: "Steamed rice, black lentil dal, seasonal tarkari, pickle and ghee.",
    priceCents: 1299,
    image:
      "https://images.unsplash.com/photo-1546833999-b9f581a1996d?auto=format&fit=crop&w=800&q=80",
    isVeg: true,
    popular: true,
    allergens: ["Dairy"],
  },
  {
    id: "m2",
    restaurantId: "r1",
    restaurantSlug: "himalayan-kitchen",
    category: "Grill",
    name: "Charcoal Sekuwa Platter",
    description: "Marinated goat sekuwa with beaten rice, onion salad and tomato achar.",
    priceCents: 1599,
    image:
      "https://images.unsplash.com/photo-1529042410759-befb1204b468?auto=format&fit=crop&w=800&q=80",
    popular: true,
  },
  {
    id: "m3",
    restaurantId: "r4",
    restaurantSlug: "kathmandu-momo-house",
    category: "Momo",
    name: "Jhol Momo (10 pcs)",
    description: "Steamed chicken momos served in a fragrant tomato-sesame broth.",
    priceCents: 899,
    image:
      "https://images.unsplash.com/photo-1496116218417-1a781b1c416c?auto=format&fit=crop&w=800&q=80",
    popular: true,
    allergens: ["Gluten", "Sesame"],
  },
  {
    id: "m4",
    restaurantId: "r2",
    restaurantSlug: "spice-garden",
    category: "Curry",
    name: "Butter Chicken",
    description: "Tandoor chicken simmered in a tomato-cashew gravy with fenugreek.",
    priceCents: 1499,
    image:
      "https://images.unsplash.com/photo-1603894584372-c003cd5307ee?auto=format&fit=crop&w=800&q=80",
    allergens: ["Dairy", "Nuts"],
    popular: true,
  },
  {
    id: "m5",
    restaurantId: "r6",
    restaurantSlug: "green-table",
    category: "Bowls",
    name: "Roasted Squash Grain Bowl",
    description: "Farro, roasted squash, herbed yoghurt and toasted seeds.",
    priceCents: 1199,
    image:
      "https://images.unsplash.com/photo-1511690656952-34342bb7c2f2?auto=format&fit=crop&w=800&q=80",
    isVeg: true,
    allergens: ["Dairy", "Seeds"],
  },
  {
    id: "m6",
    restaurantId: "r3",
    restaurantSlug: "urban-grill",
    category: "Burgers",
    name: "Smokehouse Burger",
    description: "Dry-aged beef, smoked cheddar, caramelised onion and chipotle aioli.",
    priceCents: 1699,
    image:
      "https://images.unsplash.com/photo-1568901346375-23c9450c58cd?auto=format&fit=crop&w=800&q=80",
    allergens: ["Gluten", "Dairy"],
    popular: true,
  },
];

export const offers: Offer[] = [
  {
    id: "o1",
    title: "Weekend Himalayan Feast",
    description: "15% off thali sets every Saturday noon to 3pm.",
    restaurantName: "Himalayan Kitchen",
    badge: "15% OFF",
    image:
      "https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1000&q=80",
  },
  {
    id: "o2",
    title: "Momo Midnight",
    description: "Complimentary jhol with every dozen after 9pm.",
    restaurantName: "Kathmandu Momo House",
    badge: "FREE JHOL",
    image:
      "https://images.unsplash.com/photo-1496116218417-1a781b1c416c?auto=format&fit=crop&w=1000&q=80",
  },
  {
    id: "o3",
    title: "Green Week",
    description: "Complimentary herbal cooler with bowls over $15.",
    restaurantName: "Green Table",
    badge: "BONUS DRINK",
    image:
      "https://images.unsplash.com/photo-1490645935967-10de6ba17061?auto=format&fit=crop&w=1000&q=80",
  },
];

export const cartPreview = {
  restaurantName: "Himalayan Kitchen",
  restaurantSlug: "himalayan-kitchen",
  items: [
    {
      id: "c1",
      name: "Himalayan Dal Bhat Thali",
      quantity: 1,
      unitPriceCents: 1299,
      modifiers: ["Extra ghee"],
    },
    {
      id: "c2",
      name: "Charcoal Sekuwa Platter",
      quantity: 2,
      unitPriceCents: 1599,
      modifiers: ["Mild spice"],
    },
  ],
  subtotalCents: 4497,
  deliveryFeeCents: 199,
  serviceFeeCents: 149,
  totalCents: 4845,
};

export const restaurantOrders: OrderRow[] = [
  {
    id: "ord1",
    orderNumber: "SVK-10428",
    customerName: "Anisha Rai",
    restaurantName: "Himalayan Kitchen",
    status: "New",
    totalCents: 4845,
    fulfilment: "Delivery",
    placedAt: "2 min ago",
  },
  {
    id: "ord2",
    orderNumber: "SVK-10421",
    customerName: "Prabin Thapa",
    restaurantName: "Himalayan Kitchen",
    status: "Preparing",
    totalCents: 3299,
    fulfilment: "Pickup",
    placedAt: "18 min ago",
  },
  {
    id: "ord3",
    orderNumber: "SVK-10411",
    customerName: "Maya Gurung",
    restaurantName: "Himalayan Kitchen",
    status: "Ready",
    totalCents: 2199,
    fulfilment: "Delivery",
    placedAt: "34 min ago",
  },
];

export const platformOrders: OrderRow[] = [
  ...restaurantOrders,
  {
    id: "ord4",
    orderNumber: "SVK-10430",
    customerName: "Samir KC",
    restaurantName: "Spice Garden",
    status: "Out for Delivery",
    totalCents: 5120,
    fulfilment: "Delivery",
    placedAt: "12 min ago",
  },
  {
    id: "ord5",
    orderNumber: "SVK-10405",
    customerName: "Elena Shrestha",
    restaurantName: "Green Table",
    status: "Completed",
    totalCents: 2780,
    fulfilment: "Pickup",
    placedAt: "1 hr ago",
  },
];

export const settlements: Settlement[] = [
  {
    id: "s1",
    restaurantName: "Himalayan Kitchen",
    period: "20–26 Jul 2026",
    grossCents: 1845000,
    commissionCents: 221400,
    netCents: 1623600,
    status: "Pending",
  },
  {
    id: "s2",
    restaurantName: "Kathmandu Momo House",
    period: "20–26 Jul 2026",
    grossCents: 2210000,
    commissionCents: 287300,
    netCents: 1922700,
    status: "Pending",
  },
  {
    id: "s3",
    restaurantName: "Spice Garden",
    period: "13–19 Jul 2026",
    grossCents: 1568000,
    commissionCents: 219520,
    netCents: 1348480,
    status: "Paid",
  },
];

export const testimonials = [
  {
    name: "Nisha Adhikari",
    quote:
      "Ordering from Himalayan Kitchen through Suvakamana feels as polished as dining in.",
    role: "Regular customer",
  },
  {
    name: "Rajan Bista",
    quote:
      "Our kitchen board updates instantly. Accepting orders has never been this calm.",
    role: "Owner, Urban Grill",
  },
  {
    name: "Sara Limbu",
    quote:
      "Beautiful menus, clear fees and food that arrives still fragrant. Exceptional.",
    role: "Food writer",
  },
];

export const auditLogs = [
  {
    id: "a1",
    actor: "Super Admin",
    action: "Approved restaurant application",
    subject: "Green Table",
    at: "Today · 09:14",
  },
  {
    id: "a2",
    actor: "Super Admin",
    action: "Updated commission rate to 12%",
    subject: "Himalayan Kitchen",
    at: "Yesterday · 16:42",
  },
  {
    id: "a3",
    actor: "Support",
    action: "Opened refund review",
    subject: "SVK-10388",
    at: "Yesterday · 11:05",
  },
];

export const pendingApplications = [
  {
    id: "app1",
    name: "Valley Clay Oven",
    owner: "Bikash Maharjan",
    cuisine: "Nepali",
    submitted: "28 Jul 2026",
    address: "Baneshwor Height",
  },
  {
    id: "app2",
    name: "Lotus Leaf Cafe",
    owner: "Priya Sharma",
    cuisine: "Cafe",
    submitted: "27 Jul 2026",
    address: "Jhamsikhel",
  },
];

export const restaurantStaff = [
  { id: "st1", name: "Sita Lama", role: "Manager", status: "Active" },
  { id: "st2", name: "Hari Poudel", role: "Kitchen Staff", status: "Active" },
  { id: "st3", name: "Nabin KC", role: "Order Operator", status: "Invited" },
];

export const supportTickets = [
  {
    id: "t1",
    subject: "Missing item in delivery",
    requester: "Anisha Rai",
    priority: "High",
    status: "Open",
  },
  {
    id: "t2",
    subject: "Commission statement question",
    requester: "Himalayan Kitchen",
    priority: "Medium",
    status: "In review",
  },
  {
    id: "t3",
    subject: "Partner onboarding documents",
    requester: "Valley Clay Oven",
    priority: "Low",
    status: "Waiting",
  },
];

export function getRestaurant(slug: string) {
  return restaurants.find((r) => r.slug === slug);
}

export function getMenuForRestaurant(slug: string) {
  return menuItems.filter((item) => item.restaurantSlug === slug);
}
