{{--
    Google Maps zone editor — polygon or circle.

    Google Maps JavaScript is loaded from maps.googleapis.com with the
    referrer-restricted browser key; it is the one third-party script the
    admin panel allows (CLAUDE.md, config/map.php). Place search still goes
    through PlaceLookupController with the server key.

    State: this field holds the polygon as [{lat, lng}, ...]. The circle is
    the sibling shape_type / center_lat / center_lng / radius_km fields,
    entangled below so typing in those inputs moves the map and dragging on
    the map fills those inputs. The lat/lng swap into WKT happens server-side
    in Zone::polygonWktFrom() and nowhere else.
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="zoneMap({
            points: $wire.$entangle('{{ $getStatePath() }}'),
            shapeType: $wire.$entangle('{{ $getSiblingStatePath('shape_type') }}'),
            centerLat: $wire.$entangle('{{ $getSiblingStatePath('center_lat') }}'),
            centerLng: $wire.$entangle('{{ $getSiblingStatePath('center_lng') }}'),
            radiusKm: $wire.$entangle('{{ $getSiblingStatePath('radius_km') }}'),
            parentPath: @js($getSiblingStatePath('parent_id')),
            browserKey: @js($getBrowserKey()),
            defaultView: @js($getMapCenter()),
            otherZones: @js($getOtherZones()),
            disabled: @js($isDisabled()),
        })"
        class="space-y-2"
    >
        @if ($hasPlaceSearch())
            <div class="relative" style="z-index: 20">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input
                            type="text"
                            x-model="searchQuery"
                            x-on:input="onQueryInput()"
                            x-on:keydown.enter.prevent="searchPlace()"
                            x-on:keydown.escape="searchResults = []"
                            placeholder="Search a place to jump to — building, road, area or pincode"
                            class="block w-full rounded-lg border-gray-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm placeholder:text-gray-400 focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:placeholder:text-gray-500"
                        />
                        <svg
                            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                        </svg>
                    </div>

                    <x-filament::button
                        type="button"
                        color="gray"
                        x-on:click="searchPlace()"
                        x-bind:disabled="isSearching"
                    >
                        <span x-show="! isSearching">Search</span>
                        <span x-show="isSearching" x-cloak>Searching…</span>
                    </x-filament::button>
                </div>

                {{-- Sits over the map so a long list never pushes it down. --}}
                <ul
                    x-show="searchResults.length > 0"
                    x-cloak
                    x-on:click.outside="searchResults = []"
                    style="z-index: 30"
                    class="absolute mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
                >
                    <template x-for="place in searchResults" :key="place.id">
                        <li>
                            <button
                                type="button"
                                x-on:click="goToPlace(place)"
                                class="block w-full px-3 py-2 text-left hover:bg-gray-100 dark:hover:bg-gray-700"
                            >
                                <span class="block text-sm font-medium text-gray-800 dark:text-gray-100" x-text="place.main"></span>
                                <span x-show="place.secondary" class="block text-xs text-gray-500 dark:text-gray-400" x-text="place.secondary"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        @else
            {{-- Says why there is no search box, instead of it silently not being there. --}}
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Place search is off. Add a Google Places key (with "Places API (New)" enabled) in
                @if (auth()->user()?->isSuperAdmin())
                    <a href="{{ \App\Filament\SuperAdmin\Pages\SystemSettingsPage::getUrl(panel: 'super-admin') }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Super Admin → System settings → Maps</a>
                @else
                    Super Admin → System settings → Maps (ask a super-admin)
                @endif
                to search for a place and jump to it on the map.
            </p>
        @endif

        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="hint()"></p>

        {{-- Toolbar, as on the ESH800 fleet-zone form. --}}
        <div x-show="! disabled && ! loadError" class="flex flex-wrap items-center gap-2">
            <x-filament::button type="button" size="sm" color="gray" icon="heroicon-m-pencil" x-on:click="redraw()">
                Re-draw
            </x-filament::button>

            <x-filament::button type="button" size="sm" color="gray" icon="heroicon-m-trash" x-on:click="clearShape()">
                Clear
            </x-filament::button>

            <x-filament::button type="button" size="sm" color="gray" icon="heroicon-m-arrow-uturn-left" x-on:click="resetToSaved()">
                Reset to saved
            </x-filament::button>

            <x-filament::button type="button" size="sm" color="gray" icon="heroicon-m-map-pin" x-on:click="goToCurrentLocation()">
                My location
            </x-filament::button>

            <template x-if="drawing">
                <div class="flex items-center gap-2">
                    <x-filament::button type="button" size="sm" color="gray" x-on:click="undoPoint()" x-bind:disabled="draftCount === 0">
                        Undo point
                    </x-filament::button>
                    <x-filament::button type="button" size="sm" x-on:click="finishDrawing()" x-bind:disabled="draftCount < 3">
                        Finish shape
                    </x-filament::button>
                </div>
            </template>
        </div>

        <div class="relative">
            <div
                x-ref="map"
                style="height: {{ $getHeight() }}px"
                class="w-full overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600"
            ></div>

            <div
                x-show="! ready && ! loadError"
                class="absolute inset-0 flex items-center justify-center rounded-lg bg-gray-50 text-sm text-gray-500 dark:bg-gray-900 dark:text-gray-400"
            >
                Loading Google Maps…
            </div>

            <div
                x-show="loadError"
                x-cloak
                class="absolute inset-0 flex items-center justify-center rounded-lg bg-gray-50 p-6 text-center text-sm text-danger-600 dark:bg-gray-900 dark:text-danger-400"
                x-text="loadError"
            ></div>
        </div>

        <div class="flex items-center justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-300" x-text="status()"></span>

            <span
                x-show="searchError"
                x-cloak
                x-text="searchError"
                class="text-danger-600 dark:text-danger-400"
            ></span>
        </div>
    </div>
</x-dynamic-component>

@script
<script>
    /**
     * One script tag per page, however many times Livewire re-renders the
     * form. Google calls gm_authFailure for a bad key or a referrer the key
     * is not restricted to — the map otherwise just goes grey.
     */
    const loadGoogleMaps = (key) => {
        if (window.__zoneMapLoader) {
            return window.__zoneMapLoader;
        }

        window.__zoneMapLoader = new Promise((resolve, reject) => {
            if (window.google?.maps?.importLibrary) {
                resolve();
                return;
            }

            window.__zoneMapReady = () => resolve();
            window.gm_authFailure = () => window.dispatchEvent(new CustomEvent('zone-map-auth-failed'));

            const script = document.createElement('script');
            script.src = 'https://maps.googleapis.com/maps/api/js'
                + '?key=' + encodeURIComponent(key)
                + '&v=weekly&loading=async&callback=__zoneMapReady';
            script.async = true;
            script.onerror = () => {
                window.__zoneMapLoader = null;
                reject(new Error('Google Maps failed to load'));
            };
            document.head.appendChild(script);
        });

        return window.__zoneMapLoader;
    };

    const TEAL = '#0F766E';
    const TEAL_FILL = '#14B8A6';
    const round = (value, places) => Number(Number(value).toFixed(places));
    const isNumber = (value) => value !== null && value !== '' && Number.isFinite(Number(value));

    Alpine.data('zoneMap', ({
        points,
        shapeType,
        centerLat,
        centerLng,
        radiusKm,
        parentPath,
        browserKey,
        defaultView,
        otherZones,
        disabled,
    }) => {
        // Google objects live outside Alpine's reactive state: wrapping them
        // in Alpine's proxies breaks Google's internal identity checks.
        const gm = {
            map: null,
            polygon: null,
            circle: null,
            centerMarker: null,
            draft: [],
            draftLine: null,
            draftMarkers: [],
            searchMarker: null,
        };

        return {
            points,
            shapeType,
            centerLat,
            centerLng,
            radiusKm,
            disabled,
            ready: false,
            loadError: '',
            drawing: false,
            draftCount: 0,
            saved: null,
            searchQuery: '',
            searchResults: [],
            searchError: '',
            isSearching: false,
            searchDebounce: null,
            searchRequestId: 0,
            sessionToken: null,

            async init() {
                this.saved = this.snapshot();

                if (! browserKey) {
                    this.loadError = 'Google Maps is not configured. Set GOOGLE_MAPS_BROWSER_KEY in .env (see config/map.php).';
                    return;
                }

                window.addEventListener('zone-map-auth-failed', () => {
                    this.loadError = 'Google Maps rejected the browser key. Check GOOGLE_MAPS_BROWSER_KEY, that the Maps JavaScript API is enabled, and that this site is in the key\'s HTTP referrer list.';
                });

                try {
                    await loadGoogleMaps(browserKey);
                    await google.maps.importLibrary('maps');
                    await google.maps.importLibrary('marker');
                } catch (error) {
                    this.loadError = 'Google Maps could not be loaded. Check the internet connection and GOOGLE_MAPS_BROWSER_KEY.';
                    return;
                }

                gm.map = new google.maps.Map(this.$refs.map, {
                    center: { lat: defaultView.lat, lng: defaultView.lng },
                    zoom: defaultView.zoom,
                    mapTypeControl: true,
                    mapTypeControlOptions: {
                        mapTypeIds: ['roadmap', 'satellite'],
                        style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
                        position: google.maps.ControlPosition.TOP_LEFT,
                    },
                    fullscreenControl: true,
                    streetViewControl: false,
                    clickableIcons: false,
                });

                this.drawOtherZones();
                this.render(true);

                if (! this.hasShape()) {
                    this.focusParent();
                }

                gm.map.addListener('click', (event) => this.onMapClick(event.latLng));

                this.$watch('shapeType', () => this.render(true));
                this.$watch('centerLat', () => this.syncCircleFromFields());
                this.$watch('centerLng', () => this.syncCircleFromFields());
                this.$watch('radiusKm', () => this.syncCircleFromFields());

                this.ready = true;
            },

            isCircle() {
                return this.shapeType === 'circle';
            },

            ring() {
                return Array.isArray(this.points)
                    ? this.points.filter((p) => isNumber(p?.lat) && isNumber(p?.lng))
                    : [];
            },

            hasCircle() {
                return isNumber(this.centerLat) && isNumber(this.centerLng)
                    && isNumber(this.radiusKm) && Number(this.radiusKm) > 0;
            },

            hasShape() {
                return this.isCircle() ? this.hasCircle() : this.ring().length >= 3;
            },

            snapshot() {
                return JSON.parse(JSON.stringify({
                    shapeType: this.shapeType ?? 'polygon',
                    points: this.ring(),
                    centerLat: this.centerLat ?? null,
                    centerLng: this.centerLng ?? null,
                    radiusKm: this.radiusKm ?? null,
                }));
            },

            hint() {
                if (this.disabled) {
                    return '';
                }

                if (this.isCircle()) {
                    return 'Click on the map to set the zone centre, then enter a Radius (KM) below. The marker is draggable, and the edge of the circle can be dragged to resize it.';
                }

                return 'Click on the map to place the boundary points, then click the first point (or Finish shape) to close it. Drag a point to move it, drag a midpoint to add one, right-click a point to delete it.';
            },

            status() {
                if (this.drawing) {
                    return `Drawing — ${this.draftCount} point${this.draftCount === 1 ? '' : 's'} placed. At least 3 are needed.`;
                }

                if (this.isCircle()) {
                    return this.hasCircle()
                        ? `Circle — centre ${Number(this.centerLat).toFixed(5)}, ${Number(this.centerLng).toFixed(5)}, radius ${Number(this.radiusKm)} km.`
                        : 'No centre set yet.';
                }

                const count = this.ring().length;

                return count >= 3 ? `Boundary drawn — ${count} points.` : 'No boundary drawn yet.';
            },

            /** Neighbouring zones, faint and unclickable, labelled "Name · CODE". */
            drawOtherZones() {
                for (const zone of otherZones) {
                    if (zone.points.length < 3) {
                        continue;
                    }

                    const colour = zone.active ? '#2563EB' : '#94A3B8';

                    new google.maps.Polygon({
                        map: gm.map,
                        paths: zone.points,
                        strokeColor: colour,
                        strokeOpacity: 0.7,
                        strokeWeight: 1,
                        fillColor: colour,
                        fillOpacity: 0.08,
                        clickable: false,
                        zIndex: 1,
                    });

                    new google.maps.Marker({
                        map: gm.map,
                        position: this.boundsOf(zone.points).getCenter(),
                        clickable: false,
                        icon: { path: google.maps.SymbolPath.CIRCLE, scale: 0 },
                        label: {
                            text: zone.label + (zone.active ? '' : ' (draft)'),
                            color: '#334155',
                            fontSize: '11px',
                            fontWeight: '600',
                        },
                        zIndex: 1,
                    });
                }
            },

            boundsOf(ring) {
                const bounds = new google.maps.LatLngBounds();
                ring.forEach((p) => bounds.extend({ lat: Number(p.lat), lng: Number(p.lng) }));

                return bounds;
            },

            /** A new area opened from its parent's Zone Areas tab starts over the parent. */
            focusParent() {
                const parentId = Number(this.$wire.get(parentPath));
                const parent = otherZones.find((zone) => zone.id === parentId);

                if (parent && parent.points.length >= 3) {
                    gm.map.fitBounds(this.boundsOf(parent.points), 20);
                }
            },

            removeShapes() {
                gm.polygon?.setMap(null);
                gm.polygon = null;
                gm.circle?.setMap(null);
                gm.circle = null;
                gm.centerMarker?.setMap(null);
                gm.centerMarker = null;
                this.stopDrawing();
            },

            /** Redraw whatever the current shape type and state describe. */
            render(fit = false) {
                if (! gm.map) {
                    return;
                }

                this.removeShapes();

                if (this.isCircle()) {
                    if (this.hasCircle()) {
                        this.drawCircle();

                        if (fit) {
                            gm.map.fitBounds(gm.circle.getBounds(), 20);
                        }
                    }

                    return;
                }

                const ring = this.ring();

                if (ring.length >= 3) {
                    this.drawPolygon(ring);

                    if (fit) {
                        gm.map.fitBounds(this.boundsOf(ring), 20);
                    }
                } else if (! this.disabled) {
                    this.startDrawing();
                }
            },

            onMapClick(latLng) {
                if (this.disabled) {
                    return;
                }

                if (this.isCircle()) {
                    this.centerLat = round(latLng.lat(), 7);
                    this.centerLng = round(latLng.lng(), 7);

                    // Something visible straight away; the radius field
                    // below is there to set the real value.
                    if (! isNumber(this.radiusKm) || Number(this.radiusKm) <= 0) {
                        this.radiusKm = 2;
                    }

                    this.syncCircleFromFields();

                    return;
                }

                if (this.drawing) {
                    this.addDraftPoint(latLng);
                }
            },

            // ---- Polygon -------------------------------------------------

            startDrawing() {
                this.stopDrawing();
                this.drawing = true;

                gm.draftLine = new google.maps.Polyline({
                    map: gm.map,
                    path: [],
                    strokeColor: TEAL,
                    strokeWeight: 2,
                    clickable: false,
                    zIndex: 3,
                });

                gm.map.setOptions({ draggableCursor: 'crosshair', disableDoubleClickZoom: true });
            },

            stopDrawing() {
                gm.draftLine?.setMap(null);
                gm.draftLine = null;
                gm.draftMarkers.forEach((marker) => marker.setMap(null));
                gm.draftMarkers = [];
                gm.draft = [];
                this.draftCount = 0;
                this.drawing = false;
                gm.map?.setOptions({ draggableCursor: null, disableDoubleClickZoom: false });
            },

            addDraftPoint(latLng) {
                gm.draft.push(latLng);
                gm.draftLine.setPath(gm.draft);

                const isFirst = gm.draft.length === 1;
                const marker = new google.maps.Marker({
                    map: gm.map,
                    position: latLng,
                    // Only the first point is clickable: clicking it closes
                    // the ring, the way most drawing tools behave.
                    clickable: isFirst,
                    title: isFirst ? 'Click to close the shape' : '',
                    icon: {
                        path: google.maps.SymbolPath.CIRCLE,
                        scale: isFirst ? 7 : 4,
                        fillColor: isFirst ? '#FFFFFF' : TEAL,
                        fillOpacity: 1,
                        strokeColor: TEAL,
                        strokeWeight: 2,
                    },
                    zIndex: 4,
                });

                if (isFirst) {
                    marker.addListener('click', () => this.finishDrawing());
                }

                gm.draftMarkers.push(marker);
                this.draftCount = gm.draft.length;
            },

            undoPoint() {
                if (gm.draft.length === 0) {
                    return;
                }

                gm.draft.pop();
                gm.draftMarkers.pop().setMap(null);
                gm.draftLine.setPath(gm.draft);
                this.draftCount = gm.draft.length;
            },

            finishDrawing() {
                if (gm.draft.length < 3) {
                    this.searchError = 'A boundary needs at least 3 points.';
                    return;
                }

                const ring = gm.draft.map((latLng) => ({ lat: latLng.lat(), lng: latLng.lng() }));

                this.searchError = '';
                this.stopDrawing();
                this.drawPolygon(ring);
                this.syncPolygon();
            },

            drawPolygon(ring) {
                gm.polygon = new google.maps.Polygon({
                    map: gm.map,
                    paths: ring.map((p) => ({ lat: Number(p.lat), lng: Number(p.lng) })),
                    editable: ! this.disabled,
                    strokeColor: TEAL,
                    strokeWeight: 2,
                    fillColor: TEAL_FILL,
                    fillOpacity: 0.25,
                    zIndex: 2,
                });

                const path = gm.polygon.getPath();

                // Every vertex drag, midpoint drag and deletion lands in the
                // form state at once, so the form's Save button always saves
                // what is on screen.
                ['set_at', 'insert_at', 'remove_at'].forEach((name) => {
                    path.addListener(name, () => this.syncPolygon());
                });

                gm.polygon.addListener('contextmenu', (event) => {
                    if (! this.disabled && event.vertex != null && path.getLength() > 3) {
                        path.removeAt(event.vertex);
                    }
                });
            },

            syncPolygon() {
                this.points = gm.polygon
                    ? gm.polygon.getPath().getArray().map((latLng) => ({
                        lat: round(latLng.lat(), 7),
                        lng: round(latLng.lng(), 7),
                    }))
                    : [];
            },

            // ---- Circle --------------------------------------------------

            drawCircle() {
                const center = { lat: Number(this.centerLat), lng: Number(this.centerLng) };

                gm.centerMarker = new google.maps.Marker({
                    map: gm.map,
                    position: center,
                    draggable: ! this.disabled,
                    title: 'Zone centre — drag to move',
                    zIndex: 3,
                });

                gm.circle = new google.maps.Circle({
                    map: gm.map,
                    center,
                    radius: Number(this.radiusKm) * 1000,
                    editable: ! this.disabled,
                    strokeColor: TEAL,
                    strokeWeight: 2,
                    fillColor: TEAL_FILL,
                    fillOpacity: 0.25,
                    zIndex: 2,
                });

                gm.centerMarker.addListener('drag', () => gm.circle.setCenter(gm.centerMarker.getPosition()));
                gm.centerMarker.addListener('dragend', () => this.syncCircle());
                gm.circle.addListener('radius_changed', () => this.syncCircle());
                gm.circle.addListener('center_changed', () => this.syncCircle());

                // Clicking inside the circle moves the centre, same as
                // clicking anywhere else on the map.
                gm.circle.addListener('click', (event) => this.onMapClick(event.latLng));
            },

            /** Map → form fields. */
            syncCircle() {
                if (! gm.circle) {
                    return;
                }

                const center = gm.circle.getCenter();
                const lat = round(center.lat(), 7);
                const lng = round(center.lng(), 7);
                const radius = round(gm.circle.getRadius() / 1000, 3);

                if (Number(this.centerLat) !== lat) this.centerLat = lat;
                if (Number(this.centerLng) !== lng) this.centerLng = lng;
                if (Number(this.radiusKm) !== radius) this.radiusKm = radius;

                gm.centerMarker?.setPosition(center);
            },

            /** Form fields → map. Changes the map only when the values differ, so the two directions cannot loop. */
            syncCircleFromFields() {
                if (! gm.map || ! this.isCircle()) {
                    return;
                }

                if (! this.hasCircle()) {
                    gm.circle?.setMap(null);
                    gm.circle = null;
                    gm.centerMarker?.setMap(null);
                    gm.centerMarker = null;

                    return;
                }

                if (! gm.circle) {
                    this.drawCircle();

                    return;
                }

                const lat = Number(this.centerLat);
                const lng = Number(this.centerLng);
                const radius = Number(this.radiusKm) * 1000;
                const current = gm.circle.getCenter();

                if (round(current.lat(), 7) !== round(lat, 7) || round(current.lng(), 7) !== round(lng, 7)) {
                    gm.circle.setCenter({ lat, lng });
                    gm.centerMarker.setPosition({ lat, lng });
                }

                if (Math.abs(gm.circle.getRadius() - radius) > 0.5) {
                    gm.circle.setRadius(radius);
                }
            },

            // ---- Toolbar -------------------------------------------------

            redraw() {
                this.searchError = '';

                if (this.isCircle()) {
                    // Keep the radius; the next click places a new centre.
                    this.centerLat = null;
                    this.centerLng = null;
                    this.removeShapes();

                    return;
                }

                this.points = [];
                this.removeShapes();
                this.startDrawing();
            },

            clearShape() {
                this.searchError = '';

                if (this.isCircle()) {
                    this.centerLat = null;
                    this.centerLng = null;
                    this.radiusKm = null;
                    this.removeShapes();

                    return;
                }

                this.points = [];
                this.render();
            },

            resetToSaved() {
                const saved = JSON.parse(JSON.stringify(this.saved));
                const typeChanged = this.shapeType !== saved.shapeType;

                this.searchError = '';
                this.points = saved.points;
                this.centerLat = saved.centerLat;
                this.centerLng = saved.centerLng;
                this.radiusKm = saved.radiusKm;
                this.shapeType = saved.shapeType;

                // A type change re-renders through the shapeType watcher.
                if (! typeChanged) {
                    this.render(true);
                }
            },

            goToCurrentLocation() {
                if (! navigator.geolocation) {
                    this.searchError = 'This browser cannot report a location.';
                    return;
                }

                navigator.geolocation.getCurrentPosition(
                    (position) => {
                        this.searchError = '';
                        const here = { lat: position.coords.latitude, lng: position.coords.longitude };
                        gm.map.setCenter(here);
                        gm.map.setZoom(15);
                        this.dropMarker(here, 'You are here');
                    },
                    () => {
                        // Browsers only expose geolocation on a secure
                        // origin; a plain-HTTP LAN address is the usual
                        // reason this fails in development.
                        this.searchError = 'Could not get your location. Allow location access; browsers only offer it over HTTPS or on localhost.';
                    },
                    { enableHighAccuracy: true, timeout: 10000 },
                );
            },

            // ---- Place search (server-side Google Places proxy) ----------

            onQueryInput() {
                clearTimeout(this.searchDebounce);
                this.searchError = '';

                if (this.searchQuery.trim().length < 2) {
                    this.searchResults = [];
                    return;
                }

                this.searchDebounce = setTimeout(() => this.searchPlace(), 300);
            },

            async searchPlace() {
                const term = this.searchQuery.trim();

                this.searchError = '';

                if (term.length < 2) {
                    this.searchResults = [];
                    return;
                }

                // One token per typing session: Google bills a session as a
                // single autocomplete plus the one Details lookup chosen.
                this.sessionToken ??= crypto.randomUUID();

                const requestId = ++this.searchRequestId;
                this.isSearching = true;

                try {
                    const url = new URL(@js(route('admin.places.autocomplete')));
                    url.searchParams.set('q', term);
                    url.searchParams.set('session', this.sessionToken);

                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });

                    // A newer keystroke has issued a newer request.
                    if (requestId !== this.searchRequestId) {
                        return;
                    }

                    if (! response.ok) {
                        throw new Error(response.statusText);
                    }

                    const body = await response.json();

                    if (body.error) {
                        this.searchResults = [];
                        this.searchError = body.error;
                        return;
                    }

                    this.searchResults = body.predictions ?? [];

                    if (this.searchResults.length === 0) {
                        this.searchError = 'No places matched that search.';
                    }
                } catch (error) {
                    if (requestId !== this.searchRequestId) {
                        return;
                    }

                    this.searchResults = [];
                    this.searchError = 'Place search is unavailable right now.';
                } finally {
                    if (requestId === this.searchRequestId) {
                        this.isSearching = false;
                    }
                }
            },

            async goToPlace(prediction) {
                this.searchResults = [];
                this.searchError = '';
                this.isSearching = true;

                try {
                    const url = new URL(@js(route('admin.places.details')));
                    url.searchParams.set('place_id', prediction.id);

                    if (this.sessionToken) {
                        url.searchParams.set('session', this.sessionToken);
                    }

                    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });

                    if (! response.ok) {
                        throw new Error(response.statusText);
                    }

                    const body = await response.json();

                    if (body.error || ! body.place) {
                        this.searchError = body.error ?? 'Could not look that place up.';
                        return;
                    }

                    const place = body.place;

                    // Google's own viewport where it has one, so a suburb
                    // frames the suburb and a building zooms right in.
                    if (place.viewport) {
                        gm.map.fitBounds({
                            south: place.viewport.south,
                            west: place.viewport.west,
                            north: place.viewport.north,
                            east: place.viewport.east,
                        }, 20);
                    } else {
                        gm.map.setCenter({ lat: place.lat, lng: place.lng });
                        gm.map.setZoom(17);
                    }

                    this.dropMarker({ lat: place.lat, lng: place.lng }, place.label || prediction.label);
                    this.searchQuery = place.label || prediction.label;
                } catch (error) {
                    this.searchError = 'Could not look that place up.';
                } finally {
                    // The session ends with the selection.
                    this.sessionToken = null;
                    this.isSearching = false;
                }
            },

            /**
             * A pin marking where the map was sent. Never part of the zone
             * shape — a search pin must not end up in the boundary.
             */
            dropMarker(position, label) {
                gm.searchMarker?.setMap(null);
                gm.searchMarker = new google.maps.Marker({
                    map: gm.map,
                    position,
                    title: label ?? '',
                    clickable: false,
                    icon: {
                        path: google.maps.SymbolPath.BACKWARD_CLOSED_ARROW,
                        scale: 5,
                        fillColor: '#DC6803',
                        fillOpacity: 1,
                        strokeColor: '#FFFFFF',
                        strokeWeight: 1,
                    },
                    zIndex: 5,
                });
            },
        };
    });
</script>
@endscript
