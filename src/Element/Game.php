<?php

namespace Drupal\vchess\Element;

use Drupal\Core\Render\Element\Table;
use Drupal\Core\Url;
use Drupal\vchess\Entity\Game as GameEntity;
use Drupal\vchess\Game\Board;
use Drupal\vchess\Game\Piece;
use Drupal\vchess\Game\Square;

/**
 * Creates a visible view of the game.
 *
 * Parameters:
 * - #game: The Game entity representing the game to be rendered.
 * - #player: Indicates the color of the player who is playing, and thus the
 *   viewing perspective of the board.  It is either 'w' (white) or 'b' (black).
 *   If it is empty then the game is being observed by someone who is not the
 *   player. Note that player is not necessarily the same as the person who is
 *   to move. It may be the player is white but it is black to move.
 * - #active: Indicates whether or not the board allows moves or not (i.e.
 *   whether the board is in a view-only state or not).
 * - #flipped: Indicates whether the board is flipped or not.
 * - #theme: Specifies the theme to be used for the chess pieces and board.
 *
 * @RenderElement("vchess_game")
 */
class Game extends Table {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $class = get_class($this);
    return [
      // Chess game board properties.
      '#game' => NULL,
      '#player' => 'w',
      '#flipped' => FALSE,
      '#active' => FALSE,
      '#board_theme' => 'default',
      '#refresh_interval' => 10,
      // Properties for table rendering.
      '#header' => [],
      '#rows' => [],
      '#empty' => '',
      // Properties for tableselect support.
      '#input' => TRUE,
      '#tree' => TRUE,
      '#tableselect' => FALSE,
      '#sticky' => FALSE,
      '#responsive' => FALSE,
      '#multiple' => FALSE,
      '#js_select' => TRUE,
      '#element_validate' => [
        [$class, 'validateTable'],
      ],
      // Properties for tabledrag support.
      // The value is a list of arrays that are passed to
      // drupal_attach_tabledrag(). Table::preRenderTable() prepends the HTML ID
      // of the table to each set of options.
      // @see drupal_attach_tabledrag()
      '#tabledrag' => [],
      // Render properties.
      '#pre_render' => [
        [$class, 'preRenderGameTable'],
      ],
      '#theme' => 'table__game',
    ];
  }

  public static function preRenderGameTable(array $element) {
    // Ensure there is a game to render.
    if (!$element['#game'] instanceof GameEntity) {
      return $element;
    }

    $game = $element['#game'];
    $player = $element['#player'];
    $flipped = $element['#flipped'];
    $active = $element['#active'];
    $board = (new Board())->setupPosition($game->getBoard());

    global $base_url;
    global $base_path; // e.g. "/chess_drupal-7.14/"

    $module_path = drupal_get_path('module', 'vchess');
    $full_module_url = $base_url . '/' . $module_path;

    $board_theme = $element['#board_theme'];
//  $theme = 'wikipedia';  // does not work because pieces include their own background

    $element['#attached']['library'][] = 'vchess/board';
    $element['#attached']['drupalSettings']['vchess'] = [
      'module_path' => '/' . $module_path,
      'module_url' => $base_path . $module_path,
      'full_url' => $full_module_url,
      'refresh_interval' => $element['#refresh_interval'],
      'movelist_url' => Url::fromRoute('vchess.movelist_block', ['vchess_game' => $game->id()])->toString(),
      'captured_pieces_url' => Url::fromRoute('vchess.captured_pieces_block', ['vchess_game' => $game->id()])->toString(),
    ];

    $element['#attributes']['class'][] = 'board-main';

    if (($player === 'w' && !$flipped) || ($player === 'b' && $flipped)) {
      $orientation = 'w';
      $index = 56;
      $pos_change = 1;
      $line_change = -16;
    }
    else {
      $orientation = 'b';
      $index = 7;
      $pos_change = -1;
      $line_change = 16;
    }
    // rows [1..8] are normal board ranks
    // row 0 contains cell labels "a" to "h"
    for ($rank = 8; $rank >= 0; $rank--) {
      $row = ['data' => []];
      // cell 0 contains the rank labels "1" to "8"
      // columns [1..8] are normal board columns
      for ($col = 0; $col <= 8; $col++) {
        if ($rank == 0) {
          // letters a-h at bottom
          if ($col > 0) {
            // ascii:
            // 97 => a
            // 98 => b
            // ...
            // 104 => h
            if ($orientation === 'w') {
              $c = chr(96 + $col);
            }
            else {
              $c = chr(105 - $col);
            }
            $cell = [
              'data' => $c,
              'align' => 'center',
              'class' => 'file-letters',
            ];
          }
          else {
            $cell = '';
          }
        }
        elseif ($col === 0) {
          // Number on the left.
          if ($orientation === 'w') {
            $i = $rank;
          }
          else {
            $i = 9 - $rank;
          }
          $cell = [
            'data' => [
              '#prefix' => '<b>',
              '#suffix' => '</b>',
              '#markup' => $i,
            ],
            'class' => 'board-coordinate',
          ];
        }
        else {
          // normal square
          $square = new Square();
          if ($orientation === 'w') {
            $square
              ->setColumn($col)
              ->setRow($rank);
          }
          else {
            $square
              ->setColumn(9 - $col)
              ->setRow(9 - $rank);
          }
          $piece = $board->getPiece($square);
          $piece_name = strtolower($piece->getName());
          $piece_color = $piece->getColor();

          if (($rank + 1 + $col) % 2 === 0) {
            $class = 'board-square white';
          }
          else {
            $class = 'board-square black';
          }

          if ($piece_name !== Piece::BLANK) {
            // Square with piece on it.
            $cell = [
              'data' => [
                '#type' => 'html_tag',
                '#tag' => 'div',
                '#attributes' => [
                  'class' => "$piece_color-$piece_name $board_theme board-piece",
                ],
              ],
              'class' => $class,
            ];
            if ($active) {
              // For active user, add ability to move the piece from this square.
              // Build the first part of the move e.g. "Nb1"
              $cmdpart = sprintf('%s%s', $piece->getType(), $square->getCoordinate());
              // If the piece is the opposition then the cmdpart becomes e.g. "xNb1"
              if ($piece_color !== $player) {
                $cmdpart = "x" . $cmdpart;
              }
              $cell['id'] = $square->getCoordinate();
              $cell['data-chess-command'] = $cmdpart;
              $cell['class'] .= ' enabled';
            }
          }
          else {
            // Build a blank square on the board.
            $cell = [
              'data' => [
                '#type' => 'html_tag',
                '#tag' => 'div',
                '#attributes' => [
                  'class' => 'empty-square ' . $board_theme,
                ],
              ],
              'class' => $class,
            ];
            if ($active) {
              // For active user, add ability to move a piece to this square.
              // For this we build the target part of the move e.g. "-c3", so the move will end up as "Nb1-c3"
              $cmdpart = sprintf('-%s', Square::fromIndex($index)->getCoordinate());
              $cell['id'] = $square->getCoordinate();
              $cell['data-chess-command'] = $cmdpart;
              $cell['class'] .= ' enabled';
            }
          }
          $index += $pos_change;
        }
        $row['data'][] = $cell;
      }
      $index += $line_change;
      $element['#rows'][] = $row;
    }

    // Determine the game players, take note that this element will also display
    // challenge games without complete players.
    if ($game->getWhiteUser()) {
      $white_name = $game->getWhiteUser()->getDisplayName();
    }
    else {
      $white_name = t('TBD');
    }

    if ($game->getBlackUser()) {
      $black_name = $game->getBlackUser()->getDisplayName();
    }
    else {
      $black_name = t('TBD');
    }

    // Display game heading, e.g. "white: admin - black: hugh"
    $white = '<div class="chess-player-label white-player"><span class="label">White</span><span class="name">' . $white_name . '</span></div>';
    $black = '<div class="chess-player-label black-player"><span class="label">Black</span><span class="name">' . $black_name . '</span></div>';

    if ($orientation === 'w') {
      $element['#prefix'] = $black;
      $element['#suffix'] = $white; //[['data' => $white, 'colspan' => '9', 'class' => ['chess-board-footer']]];
    }
    else {
      $element['#prefix'] = $white;
      $element['#suffix'] = $black;
    }

    // Unset game so we don't end up re-rendering.
    unset($element['#game']);
    return $element;
  }

}
