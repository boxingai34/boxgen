# AI Booru Prompt Generator - Project Context

## Overview

User sedang membangun website AI Prompt Generator yang bertujuan membuat prompt akurat untuk berbagai AI image/video generator.

Fokus utama:

- Character prompt generation
- Outfit generation
- Pose generation
- Condition/damage state generation
- Background/environment generation
- Anime/fantasy character database
- Seedance 2.0 video prompt generation

Sistem harus menggunakan database tag dari booru (terutama Danbooru) agar prompt lebih kompatibel dengan model anime.

Tujuan utama:

> Mengubah input sederhana user menjadi prompt berkualitas tinggi dengan struktur modular, akurat, hemat token, dan konsisten.

---

# Core Architecture

Website terdiri dari beberapa engine:
User Input
    |
    |
Tag Resolver Engine
    |
    |
Character Database
    |
    |
Prompt Builder
    |
    |
Optimization Layer
    |
    |
Output Engine
    |
    |
NovelAI / SD / Gemini / Seedance 2.0


---

# Main Principle

Jangan membuat prompt dari imajinasi AI saja.

Prioritas:

1. Gunakan tag resmi dari booru.
2. Gunakan alias mapping.
3. Gunakan relationship antar tag.
4. Gunakan template modular.
5. Gunakan AI hanya untuk optimasi bahasa.

---

# Supported Output Mode

Website memiliki beberapa mode.

## Mode 1 - Image Prompt

Target:

- NovelAI
- Stable Diffusion
- Anime image model
- Gemini image generation


Output:

Keyword based prompt.

Contoh:
masterpiece,
best quality,
1girl,
maki_zenin,
jujutsu_kaisen,
boxing_gloves,
boxing_outfit,
fighting_stance,
outdoor

---

## Mode 2 - Seedance 2.0 Video Prompt

Target:

- Seedance 2.0
- AI video generation


Output:

Natural cinematic language.

Tidak menggunakan keyword spam.

Contoh:
Private anime boxing match inside an underground gym.
Boxer A and Boxer B circle each other inside the ring.
Boxer A throws a quick combination while Boxer B blocks and changes position.
Handheld camera follows the fighters from ringside.
Close-up shots capture gloves, footwork and reactions.
Anime cinematic style with dynamic lighting.

---

# Database Structure

## Character Database

Fields:
id
character_name
series
aliases
booru_tags
gender
age_category
visual_traits
default_outfit
compatible_outfits
fighting_style
popularity
last_update

---

Example:

Character:
Maki Zenin
Series:
Jujutsu Kaisen
Booru Tags:
maki_zenin
jujutsu_kaisen
green_hair
glasses


---

# Booru Integration

Primary source:

- Danbooru API

Secondary:

- Gelbooru
- Safebooru


Database harus dapat:

- Sync tag baru
- Update popularity
- Detect aliases
- Store tag category


---

# Automatic Update System

Flow:
Scheduled Update
|
|
Fetch Booru API
    |
    |
Compare Database
    |
    |
    Insert New Tags
    |
    |
    Update Existing Tags

    
---

# Tag Categories


## Character Tags

Example:
maki_zenin
elsa
sailor_moon
amy_rose

---

## Appearance Tags

Example:

long_hair
blue_eyes
green_hair
glasses

---

## Outfit Tags

Example:

boxing_gloves
boxing_trunks
sports_bra
tank_top
leggings


---

## Pose Tags

Example:
fighting_stance
boxing_pose
punching
uppercut
blocking


---

## Condition Tags

Example:

bruised_face
sweat
tired
injured
exhausted

---

## Background Tags

Example:

boxing_ring
gym
night
rain
snow


---

# Outfit System


Outfit harus menjadi modul terpisah.


## Pro Fight

Professional boxing style.

Tags:

boxing trunks
sports bra
boxing gloves
athletic wear


---

## Underground

Private underground fight style.

Tags:

tight outfit
combat wear
boxing gear


---

## Training

Training clothes.

Tags:
tank top
crop top
sportswear
leggings


---

## Private Match

Closed match environment.

Tags:

minimal fightwear
custom boxing outfit


---

# Pose System


Pose hanya mengatur:

- Body position
- Camera composition
- Movement


Tidak memasukkan:

- Damage
- Story
- Emotion


Categories:


## Standing

Examples:

fighting stance
guard position
victory pose
champion stance

---

## Attack

Examples:

jab
hook punch
uppercut
body punch

---

## Defense

Examples:

blocking
dodging
counter stance


---

## Recovery

Examples:
kneeling
sitting
resting
recovering

---

# Condition System


Condition adalah progression.


Example:


Round 1:
fresh condition

Round 3:
light fatigue
minor bruises

Round 6:
moderate facial damage
swollen lip


Round 10:
heavy fatigue
near knockout

---

Condition dibagi:


## Facial Damage

bruised face
swollen eye
split lip

## Body Condition

tired
exhausted
fatigue

## Clothing Condition
dirty clothes
damaged outfit
---

# Background System


Structure:

Universe
Location
Time
Weather
Lighting
Atmosphere

Example:

Input:
Frozen
Indoor


Output:

ice palace
blue lighting
snow atmosphere
fantasy environment


---

# Prompt Builder


Prompt order:

1. Quality / Style
2. Character Identity
3. Appearance
4. Outfit
5. Pose
6. Condition
7. Background
8. Camera
9. Lighting
10. Negative Prompt


---

# Negative Prompt Generator


Every prompt must include negative prompt.


Base:

low quality,
bad anatomy,
bad hands,
extra fingers,
deformed face,
wrong proportions,
blurry,
watermark,
text


Anime:

3d render,
photorealistic,
realistic photo



---

# Seedance 2.0 Prompt Engine


## Philosophy

Video prompt bukan keyword generator.

Video prompt harus berpikir seperti sutradara film.


Prioritas:

1. Scene
2. Character
3. Action
4. Camera
5. Environment
6. Timing
7. Style


---

# Seedance Prompt Structure


Template:

[Scene Setup]
[Character Reference]
[Action]
[Camera Movement]
[Environment]
[Lighting]
[Ending]


---

# Reference Handling


Jika menggunakan image reference:


Gunakan:

@Image1 = Boxer A appearance reference
@Image2 = Boxer B appearance reference


Jelaskan fungsi reference:

Maintain character identity, hairstyle, outfit design and visual consistency.


Jangan menjelaskan ulang semua detail karakter.

---

# Seedance Camera Library


## Close Up
close-up shot focusing on face and gloves



## Low Angle
low angle shot from ring level


## Tracking
handheld tracking shot following movement



## Overhead
top-down cinematic shot


## Dutch Angle
slightly tilted dramatic camera angle

---

# Seedance Action Library


Gunakan aksi visual.


Good:

Boxer A throws a quick left hook.
Boxer B blocks and steps backward.

Avoid:
Boxer A destroys Boxer B brutally.



Gunakan:

- movement
- reaction
- position
- camera


---

# Seedance Token Optimization


Prioritas token:


HIGH:

- character identity
- action
- camera
- environment


MEDIUM:

- lighting
- atmosphere


LOW:

- excessive adjectives


Hindari:
beautiful amazing stunning ultra detailed masterpiece cinematic


Jika reference sudah kuat, jangan ulang detail.


---

# Safety Rewrite Layer


Sebelum output:

Raw prompt

↓

Analyze:

- ambiguous wording
- unnecessary extreme wording
- unclear action


↓

Rewrite:

Cinematic description.


Fokus:

- choreography
- camera
- environment
- acting


---

# Prompt Regeneration System


User dapat mengganti bagian tertentu.


Example:

Change only pose

atau:
Change background only



Character identity tetap.


---

# Future Features


## Character Relationship Database

Contoh:

Maki Zenin
compatible:
combat style
boxing
martial arts
athletic pose

---

## Prompt Preset

Save:
Elsa Pro Fight
Maki Underground
Sailor Moon Private Match


---

## AI Prompt Optimizer

Raw:

Maki boxing

↓

Optimized:
1girl,
maki_zenin,
jujutsu_kaisen,
boxing outfit,
boxing gloves,
fighting stance,
cinematic composition


---

# Final Goal

Membangun sebuah AI Prompt CMS.

Bukan hanya prompt generator.

Sistem harus memiliki:

- Tag database
- Character database
- Outfit database
- Pose database
- Condition database
- Background database
- Motion database
- Camera database
- Prompt optimizer


Output harus:

- Akurat
- Konsisten
- Hemat token
- Mudah diupdate
- Bisa digunakan untuk ribuan karakter
- Mendukung image dan video generation