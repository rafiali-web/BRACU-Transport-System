<?php
/**
 * Live Tracking page for University Bus Booking System
 * Shows real-time location tracking
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

require_once '../includes/header.php';
?>

<h1 class="page-title">Live Bus Tracking <i class="fas fa-satellite"></i></h1>

<div class="card">
    <div class="live-tracking-header">
        <div class="tracking-info">
            <h3><i class="fas fa-location-dot"></i> Real-time Location</h3>
            <p>View your current location and nearby buses in real-time</p>
        </div>
        <div class="tracking-status">
            <span class="status-badge online"><i class="fas fa-circle"></i> Live</span>
        </div>
    </div>

    <div class="map-container" id="mapContainer">
        <div class="map-placeholder" id="mapPlaceholder">
            <i class="fas fa-map-marked-alt fa-4x"></i>
            <h3>Live Map</h3>
            <p>Click below to start tracking your location</p>
            <button class="btn btn-primary" onclick="initMap()">
                <i class="fas fa-play"></i> Start Tracking
            </button>
        </div>
        <div class="actual-map" id="actualMap" style="display: none; height: 400px; background: #f0f0f0; border-radius: 8px; position: relative;">
        </div>
    </div>

    <div class="tracking-controls">
        <div class="control-group">
            <button class="btn btn-outline" onclick="refreshLocation()">
                <i class="fas fa-sync-alt"></i> Refresh Location
            </button>
            <button class="btn btn-outline" onclick="toggleFullscreen()">
                <i class="fas fa-expand"></i> Fullscreen
            </button>
        </div>
        
        <div class="location-info">
            <h4><i class="fas fa-info-circle"></i> Location Details</h4>
            <div id="locationData">
                <p>Latitude: <span id="latitude">--</span></p>
                <p>Longitude: <span id="longitude">--</span></p>
                <p>Accuracy: <span id="accuracy">--</span> meters</p>
                <p>Last Updated: <span id="lastUpdate">--</span></p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <h3><i class="fas fa-bus"></i> Nearby Buses</h3>
    <div class="nearby-buses" id="nearbyBuses">
        <div class="loading-buses">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Enable tracking to see nearby buses...</p>
        </div>
    </div>
</div>

<script>
let watchId = null;
let currentLocation = null;

function initMap() {
    if (navigator.geolocation) {
        document.getElementById('mapPlaceholder').style.display = 'none';
        document.getElementById('actualMap').style.display = 'block';
        
        watchId = navigator.geolocation.watchPosition(
            showPosition,
            handleLocationError,
            { enableHighAccuracy: true, timeout: 5000, maximumAge: 0 }
        );
        
        loadNearbyBuses();
    } else {
        alert('Geolocation is not supported by this browser.');
    }
}

function showPosition(position) {
    currentLocation = {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: position.coords.accuracy,
        timestamp: new Date(position.timestamp)
    };
    
    document.getElementById('latitude').textContent = currentLocation.latitude.toFixed(6);
    document.getElementById('longitude').textContent = currentLocation.longitude.toFixed(6);
    document.getElementById('accuracy').textContent = Math.round(currentLocation.accuracy);
    document.getElementById('lastUpdate').textContent = currentLocation.timestamp.toLocaleTimeString();
    
    updateMapVisualization();
}

function updateMapVisualization() {
    const mapElement = document.getElementById('actualMap');
    mapElement.innerHTML = `
        <div style="padding: 20px; text-align: center;">
            <div style="position: relative; height: 300px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);">
                    <i class="fas fa-map-marker-alt fa-3x" style="color: #e74c3c; text-shadow: 0 0 10px rgba(0,0,0,0.5);"></i>
                </div>
                <div style="position: absolute; bottom: 20px; left: 20px; background: rgba(255,255,255,0.9); padding: 10px; border-radius: 5px;">
                    <strong>Your Location</strong><br>
                    ${currentLocation.latitude.toFixed(6)}, ${currentLocation.longitude.toFixed(6)}
                </div>
            </div>
            <p style="margin-top: 10px;"><small>In a real implementation, this would show an actual interactive map</small></p>
        </div>
    `;
}

function handleLocationError(error) {
    console.error('Geolocation error:', error);
    let errorMessage = 'Unable to retrieve your location';
    
    switch(error.code) {
        case error.PERMISSION_DENIED:
            errorMessage = 'Location access denied. Please enable location services in your browser settings.';
            break;
        case error.POSITION_UNAVAILABLE:
            errorMessage = 'Location information is unavailable.';
            break;
        case error.TIMEOUT:
            errorMessage = 'Location request timed out. Please try again.';
            break;
    }
    
    document.getElementById('mapPlaceholder').innerHTML = `
        <i class="fas fa-exclamation-triangle fa-3x" style="color: #e74c3c;"></i>
        <h3>Location Error</h3>
        <p>${errorMessage}</p>
        <button class="btn btn-primary" onclick="initMap()">
            <i class="fas fa-redo"></i> Try Again
        </button>
    `;
    document.getElementById('mapPlaceholder').style.display = 'block';
    document.getElementById('actualMap').style.display = 'none';
}

function refreshLocation() {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
    }
    initMap();
}

function toggleFullscreen() {
    const mapContainer = document.getElementById('mapContainer');
    if (!document.fullscreenElement) {
        mapContainer.requestFullscreen().catch(err => {
            alert(`Error attempting to enable fullscreen: ${err.message}`);
        });
    } else {
        document.exitFullscreen();
    }
}

function loadNearbyBuses() {
    const busesContainer = document.getElementById('nearbyBuses');
    busesContainer.innerHTML = `
        <div class="loading-buses">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Scanning for nearby buses...</p>
        </div>
    `;
    
    setTimeout(() => {
        const demoBuses = [
            { id: 'BUS-001', number: 'B23', route: 'Campus - Downtown', distance: '0.8 km', eta: '5 min' },
            { id: 'BUS-002', number: 'B17', route: 'Campus - Student Village', distance: '1.2 km', eta: '8 min' },
            { id: 'BUS-003', number: 'B15', route: 'Campus - Faculty Housing', distance: '2.1 km', eta: '12 min' }
        ];
        
        busesContainer.innerHTML = '';
        
        demoBuses.forEach(bus => {
            const busElement = document.createElement('div');
            busElement.className = 'bus-item';
            busElement.innerHTML = `
                <div class="bus-info">
                    <h4>Bus ${bus.number} <span class="bus-distance">${bus.distance}</span></h4>
                    <p>${bus.route}</p>
                    <div class="bus-eta">
                        <i class="fas fa-clock"></i> ETA: ${bus.eta}
                    </div>
                </div>
                <div class="bus-actions">
                    <button class="btn btn-sm btn-outline">
                        <i class="fas fa-eye"></i> View
                    </button>
                </div>
            `;
            busesContainer.appendChild(busElement);
        });
    }, 2000);
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('auto') === 'true') {
        initMap();
    }
});
</script>

<style>
.live-tracking-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
.tracking-status .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.9rem; font-weight: bold; }
.status-badge.online { background-color: var(--secondary); color: white; animation: pulse-live 2s infinite; }
.status-badge i { font-size: 0.6rem; margin-right: 5px; }
.map-container { margin: 20px 0; border-radius: 8px; overflow: hidden; }
.map-placeholder { text-align: center; padding: 60px 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px; }
.map-placeholder i { margin-bottom: 15px; }
.tracking-controls { display: flex; justify-content: space-between; align-items: flex-start; margin-top: 20px; gap: 30px; }
.control-group { display: flex; gap: 10px; flex-wrap: wrap; }
.location-info { flex: 1; background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid var(--primary); }
.location-info h4 { margin-bottom: 10px; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.nearby-buses { margin-top: 15px; }
.loading-buses { text-align: center; padding: 30px; color: var(--grey); }
.bus-item { display: flex; justify-content: space-between; align-items: center; padding: 15px; margin: 10px 0; background: #f8f9fa; border-radius: 8px; border-left: 4px solid var(--primary); transition: transform 0.2s ease; }
.bus-item:hover { transform: translateX(5px); }
.bus-info h4 { margin-bottom: 5px; display: flex; align-items: center; gap: 10px; }
.bus-distance { background: var(--primary); color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; }
.bus-eta { color: var(--secondary); font-weight: bold; margin-top: 5px; display: flex; align-items: center; gap: 5px; }
.no-buses { text-align: center; padding: 20px; color: var(--grey); font-style: italic; }
.btn-outline { background: transparent; border: 2px solid var(--primary); color: var(--primary); transition: all 0.3s ease; }
.btn-outline:hover { background: var(--primary); color: white; transform: translateY(-2px); }

@keyframes pulse-live {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

@media (max-width: 768px) {
    .live-tracking-header { flex-direction: column; align-items: flex-start; gap: 10px; }
    .tracking-controls { flex-direction: column; gap: 20px; }
    .control-group { justify-content: center; }
    .bus-item { flex-direction: column; align-items: flex-start; gap: 10px; }
    .bus-actions { align-self: stretch; text-align: center; }
    .location-info { text-align: center; }
}
</style>

<?php
require_once '../includes/footer.php';
?>