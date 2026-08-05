// Service Worker for TGG Members PWA
const CACHE_NAME = 'tgg-members-cache-v1';
const ASSETS = [
  'assets/css/style.css',
  'favicon.png',
  'favicon.ico',
  'icon-192.png',
  'icon-512.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS).catch(() => {
        // Fallback if some assets fail to load offline initially
      });
    })
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
});

self.addEventListener('fetch', (e) => {
  // Network first falling back to cache
  e.respondWith(
    fetch(e.request).catch(() => {
      return caches.match(e.request);
    })
  );
});
