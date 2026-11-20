<?php

/*
 * Judged queries for search ranking (architecture.md §12.4, increment 3.5),
 * against the SearchRankingFixtureSeeder doctors. `relevant` lists the doctors
 * a good answer contains; `first`, when set, must be the top result (an exact
 * name). Synthetic names only: no real people.
 */
return [
    ['q' => 'maria santos', 'relevant' => ['Dr. Maria Santos'], 'first' => 'Dr. Maria Santos'],
    ['q' => 'jose reyes', 'relevant' => ['Dr. Jose Reyes'], 'first' => 'Dr. Jose Reyes'],
    ['q' => 'santos', 'relevant' => ['Dr. Maria Santos', 'Dr. Mark Santos']],
    ['q' => 'mark santos', 'relevant' => ['Dr. Mark Santos'], 'first' => 'Dr. Mark Santos'],
    ['q' => 'pediatrics', 'relevant' => ['Dr. Maria Santos', 'Dr. Carla Mendoza', 'Dr. Paolo Lim']],
    ['q' => 'pediatrician', 'relevant' => ['Dr. Maria Santos', 'Dr. Carla Mendoza', 'Dr. Paolo Lim']],
    ['q' => 'pedia', 'relevant' => ['Dr. Maria Santos', 'Dr. Carla Mendoza', 'Dr. Paolo Lim']],
    ['q' => 'doktor ng bata', 'relevant' => ['Dr. Maria Santos', 'Dr. Carla Mendoza', 'Dr. Paolo Lim']],
    ['q' => 'cardiology', 'relevant' => ['Dr. Jose Reyes', 'Dr. Mark Santos', 'Dr. Ramon Garcia']],
    ['q' => 'cardiologist', 'relevant' => ['Dr. Jose Reyes', 'Dr. Mark Santos', 'Dr. Ramon Garcia']],
    ['q' => 'heart doctor', 'relevant' => ['Dr. Jose Reyes', 'Dr. Mark Santos', 'Dr. Ramon Garcia']],
    ['q' => 'cardiolgy', 'relevant' => ['Dr. Jose Reyes', 'Dr. Mark Santos', 'Dr. Ramon Garcia']],
    ['q' => 'dermatology', 'relevant' => ['Dr. Ana Cruz', 'Dr. Bea Torres']],
    ['q' => 'derma', 'relevant' => ['Dr. Ana Cruz', 'Dr. Bea Torres']],
    ['q' => 'skin doctor makati', 'relevant' => ['Dr. Ana Cruz']],
    ['q' => 'ob gyn', 'relevant' => ['Dr. Liza Bautista', 'Dr. Grace Villanueva']],
    ['q' => 'obstetrician', 'relevant' => ['Dr. Liza Bautista', 'Dr. Grace Villanueva']],
    ['q' => 'ent', 'relevant' => ['Dr. Noel Aquino']],
    ['q' => 'ear nose and throat', 'relevant' => ['Dr. Noel Aquino']],
    ['q' => 'eye doctor', 'relevant' => ['Dr. Irene Ramos']],
    ['q' => 'ophthalmologist', 'relevant' => ['Dr. Irene Ramos']],
    ['q' => 'internist', 'relevant' => ['Dr. Victor Dela Cruz', 'Dr. Joy Fernandez']],
    ['q' => 'family doctor', 'relevant' => ['Dr. Tess Navarro']],
    ['q' => 'cebu cardiology', 'relevant' => ['Dr. Ramon Garcia']],
    ['q' => 'bautista', 'relevant' => ['Dr. Liza Bautista'], 'first' => 'Dr. Liza Bautista'],
];
