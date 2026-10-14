<?php

return [
    /*
    | Radius (metres) of the circle a location is shown as on public surfaces
    | (marketplace, map, request feed). The exact point always lies inside it
    | and is revealed only to the two parties once work has started.
    */
    'public_radius_m' => (int) env('LOCATION_PUBLIC_RADIUS_M', 1000),
];
