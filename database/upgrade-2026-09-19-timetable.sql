-- Owner's 10-stop timetable poster (19 Sep 2026). Mehsana is no longer a pickup.
-- Route 2 = Surat -> Rupaidiha (dep 13:00), route 7 = Rupaidiha -> Surat (dep 18:00).
-- New towns carry no coordinates until real pins are known (NULL = no map pin, never a guess).
START TRANSACTION;

DELETE FROM route_stops WHERE route_id = 2 AND stop_type = 'boarding';
INSERT INTO route_stops (route_id, stop_type, stop_name, landmark, stop_time, latitude, longitude, is_border, is_meal_halt, sort_order) VALUES
 (2, 'boarding', 'Surat',                              'Bus stand (designated point)', '13:00:00', 21.1702000, 72.8311000, 0, 0, 1),
 (2, 'boarding', 'Kamrej',                             'Shiv Shakti Hotel',            '13:30:00', NULL,       NULL,       0, 0, 2),
 (2, 'boarding', 'Ankleshwar',                         'Ada Bridge',                   '15:00:00', NULL,       NULL,       0, 0, 3),
 (2, 'boarding', 'Bharuch',                            'Somnath Mahadev Mandir',       '16:00:00', NULL,       NULL,       0, 0, 4),
 (2, 'boarding', 'Vadodara',                           'Golden Chokdi',                '17:00:00', 22.3072000, 73.1812000, 0, 0, 5),
 (2, 'boarding', 'Anand',                              'Pipal Chautra',                '18:30:00', NULL,       NULL,       0, 0, 6),
 (2, 'boarding', 'Nadiad',                             'Nadiad Bridge (under bridge)', '20:00:00', NULL,       NULL,       0, 0, 7),
 (2, 'boarding', 'Emli Bhupal',                        'Taj Hotel',                    '21:00:00', NULL,       NULL,       0, 0, 8),
 (2, 'boarding', 'S Hari Parking, Nana Chiloda (Amd)', NULL,                           '23:00:00', 23.1710000, 72.6230000, 0, 0, 9);

DELETE FROM route_stops WHERE route_id = 7 AND stop_type = 'drop';
INSERT INTO route_stops (route_id, stop_type, stop_name, landmark, stop_time, latitude, longitude, is_border, is_meal_halt, sort_order) VALUES
 (7, 'drop', 'S Hari Parking, Nana Chiloda (Amd)', NULL,                           NULL, 23.1710000, 72.6230000, 0, 0, 1),
 (7, 'drop', 'Emli Bhupal',                        'Taj Hotel',                    NULL, NULL,       NULL,       0, 0, 2),
 (7, 'drop', 'Nadiad',                             'Nadiad Bridge (under bridge)', NULL, NULL,       NULL,       0, 0, 3),
 (7, 'drop', 'Anand',                              'Pipal Chautra',                NULL, NULL,       NULL,       0, 0, 4),
 (7, 'drop', 'Vadodara',                           'Golden Chokdi',                NULL, 22.3072000, 73.1812000, 0, 0, 5),
 (7, 'drop', 'Bharuch',                            'Somnath Mahadev Mandir',       NULL, NULL,       NULL,       0, 0, 6),
 (7, 'drop', 'Ankleshwar',                         'Ada Bridge',                   NULL, NULL,       NULL,       0, 0, 7),
 (7, 'drop', 'Kamrej',                             'Shiv Shakti Hotel',            NULL, NULL,       NULL,       0, 0, 8),
 (7, 'drop', 'Surat',                              'Bus stand (designated point)', NULL, 21.1702000, 72.8311000, 0, 0, 9);

COMMIT;

-- follow-up (same night): landmark wording without nested brackets
UPDATE route_stops SET landmark = 'Bus stand / designated point' WHERE route_id IN (2,7) AND landmark = 'Bus stand (designated point)';
UPDATE route_stops SET landmark = 'Nadiad Bridge – under bridge' WHERE route_id IN (2,7) AND landmark = 'Nadiad Bridge (under bridge)';
