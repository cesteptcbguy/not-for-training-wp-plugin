# HEIC GPS neutralization: exiftool before and after

Evidence for the HEIC GPS handling in `includes/heic-gps.php`. Recorded 2026-11-24 with ExifTool 13.55 on macOS.

## How the files were made

- **Before** files are the test fixtures in `tests/fixtures/`:
  - `real-gps.heic`: a real HEIC encoded by Apple ImageIO (`sips`), made by `tests/bin/make-real-heic.sh` from `heic-source-gps.jpg`.
  - `heic-gps-le.heic`: synthetic, little-endian EXIF in `mdat`, plus an XMP item with GPS.
  - `heic-gps-be.heic`: synthetic, big-endian EXIF stored in `idat` (iloc version 1).
- **After** files are the output of `notfortraining_strip_gps_heic_data()` on those bytes. This is the same function the upload hook and the post-generation pass call.
- **Values only** is the rejected alternative: GPS value bytes zeroed, tag entries kept.

The tag list used for the before and after runs:

```
-a -G1 -s -IFD0:all -ExifIFD:DateTimeOriginal -GPS:all -XMP-exif:all -XMP-dc:Rights -XMP-photoshop:Credit -Composite:GPSPosition -System:FileSize#
```

Shown below as `<tags>`.

## Summary

| File | GPS before | GPS after | Other EXIF and XMP | File size | Validate after |
| --- | --- | --- | --- | --- | --- |
| real-gps.heic | 6 EXIF GPS tags | none | unchanged | 1797, unchanged | OK |
| heic-gps-le.heic | 6 EXIF GPS tags, 6 XMP GPS properties | none | unchanged | 2109, unchanged | OK |
| heic-gps-be.heic | 6 EXIF GPS tags | none | unchanged | 611, unchanged | OK |

The before file `heic-gps-le.heic` gives one validation warning: "XMP format error (no closing tag for exif:GPSProcessingMethod)". That fixture puts `>` inside an attribute value (`note="a>b"`) on a GPS element on purpose, to test the plugin's tag matching. It is valid XML, but exiftool's XMP reader stops at it. This is also why `UserComment` is missing from that before listing. The GPS element is blanked, so the after file validates OK and exiftool reads the rest of the XMP.

## Why the GPS IFD is emptied rather than zeroed

The last section shows the alternative of zeroing only the values. exiftool still lists six GPS tags with empty or broken values. macOS Preview (ImageIO) reads that file as latitude 0, longitude 0, a location in the Gulf of Guinea. Emptying the GPS IFD (entry count 0) leaves both tools reporting no location at all, and every file stays valid.

## real-gps.heic

### Before

```
$ exiftool <tags> real-gps.before.heic
[IFD0]          Make                            : TestCam
[IFD0]          Model                           : TC-1 Mark II
[IFD0]          XResolution                     : 96
[IFD0]          YResolution                     : 96
[IFD0]          ResolutionUnit                  : inches
[IFD0]          Artist                          : Jane Doe
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[ExifIFD]       DateTimeOriginal                : 2026:11:20 14:05:09
[GPS]           GPSVersionID                    : 2.3.0.0
[GPS]           GPSLatitudeRef                  : North
[GPS]           GPSLatitude                     : 45 deg 31' 12.34"
[GPS]           GPSLongitudeRef                 : West
[GPS]           GPSLongitude                    : 122 deg 40' 56.78"
[GPS]           GPSAltitude                     : 15 m
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[Composite]     GPSPosition                     : 45 deg 31' 12.34" N, 122 deg 40' 56.78" W
[System]        FileSize                        : 1797

$ exiftool -validate -warning -error -a -G1 -s real-gps.before.heic
[ExifTool]      Validate                        : OK
```

### After

```
$ exiftool <tags> real-gps.after.heic
[IFD0]          Make                            : TestCam
[IFD0]          Model                           : TC-1 Mark II
[IFD0]          XResolution                     : 96
[IFD0]          YResolution                     : 96
[IFD0]          ResolutionUnit                  : inches
[IFD0]          Artist                          : Jane Doe
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[ExifIFD]       DateTimeOriginal                : 2026:11:20 14:05:09
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[System]        FileSize                        : 1797

$ exiftool -validate -warning -error -a -G1 -s real-gps.after.heic
[ExifTool]      Validate                        : OK
```

## heic-gps-le.heic

### Before

```
$ exiftool <tags> heic-gps-le.before.heic
[IFD0]          Make                            : TestCam
[IFD0]          Model                           : TC-1 Mark II
[IFD0]          Artist                          : Jane Doe
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[ExifIFD]       DateTimeOriginal                : 2026:11:20 14:05:09
[GPS]           GPSVersionID                    : 2.3.0.0
[GPS]           GPSLatitudeRef                  : North
[GPS]           GPSLatitude                     : 45 deg 31' 12.34"
[GPS]           GPSLongitudeRef                 : West
[GPS]           GPSLongitude                    : 122 deg 40' 56.78"
[GPS]           GPSAltitude                     : 15 m
[XMP-exif]      GPSLatitude                     : 45 deg 31' 12.34" N
[XMP-exif]      GPSLongitude                    : 122 deg 40' 56.78" W
[XMP-exif]      ExposureTime                    : 1/125
[XMP-exif]      GPSAltitude                     : 15 m
[XMP-exif]      GPSVersionID                    : 2.3.0.0
[XMP-exif]      GPSAreaInformation              : Portland, Oregon
[XMP-exif]      GPSDestLatitude                 : 45 deg 0' 0.00" N
[XMP-exif]      GPSDestLatitude                 : 31 deg 0' 0.00" N
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     GPSPosition                     : 45 deg 31' 12.34" N, 122 deg 40' 56.78" W
[System]        FileSize                        : 2109

$ exiftool -validate -warning -error -a -G1 -s heic-gps-le.before.heic
[ExifTool]      Validate                        : 1 Warning
[ExifTool]      Warning                         : XMP format error (no closing tag for exif:GPSProcessingMethod)
```

### After

```
$ exiftool <tags> heic-gps-le.after.heic
[IFD0]          Make                            : TestCam
[IFD0]          Model                           : TC-1 Mark II
[IFD0]          Artist                          : Jane Doe
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[ExifIFD]       DateTimeOriginal                : 2026:11:20 14:05:09
[XMP-exif]      ExposureTime                    : 1/125
[XMP-exif]      UserComment                     : Not for AI training.
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[System]        FileSize                        : 2109

$ exiftool -validate -warning -error -a -G1 -s heic-gps-le.after.heic
[ExifTool]      Validate                        : OK
```

## heic-gps-be.heic

### Before

```
$ exiftool <tags> heic-gps-be.before.heic
[IFD0]          Make                            : TestCam
[IFD0]          Model                           : TC-1 Mark II
[IFD0]          Artist                          : Jane Doe
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[ExifIFD]       DateTimeOriginal                : 2026:11:20 14:05:09
[GPS]           GPSVersionID                    : 2.3.0.0
[GPS]           GPSLatitudeRef                  : North
[GPS]           GPSLatitude                     : 45 deg 31' 12.34"
[GPS]           GPSLongitudeRef                 : West
[GPS]           GPSLongitude                    : 122 deg 40' 56.78"
[GPS]           GPSAltitude                     : 15 m
[Composite]     GPSPosition                     : 45 deg 31' 12.34" N, 122 deg 40' 56.78" W
[System]        FileSize                        : 611

$ exiftool -validate -warning -error -a -G1 -s heic-gps-be.before.heic
[ExifTool]      Validate                        : OK
```

### After

```
$ exiftool <tags> heic-gps-be.after.heic
[IFD0]          Make                            : TestCam
[IFD0]          Model                           : TC-1 Mark II
[IFD0]          Artist                          : Jane Doe
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[ExifIFD]       DateTimeOriginal                : 2026:11:20 14:05:09
[System]        FileSize                        : 611

$ exiftool -validate -warning -error -a -G1 -s heic-gps-be.after.heic
[ExifTool]      Validate                        : OK
```

## Rejected alternative: values zeroed, tags kept (real-gps.heic)

```
$ exiftool -a -G1 -s -GPS:all -Composite:GPSPosition real-gps.values-only.heic
[GPS]           GPSVersionID                    : 0.0.0.0
[GPS]           GPSLatitudeRef                  : Unknown ()
[GPS]           GPSLatitude                     :
[GPS]           GPSLongitudeRef                 : Unknown ()
[GPS]           GPSLongitude                    :
[GPS]           GPSAltitude                     : undef
```
