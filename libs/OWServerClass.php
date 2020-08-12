<?php

declare(strict_types=1);
require_once __DIR__ . '/../libs/UTF8Helper.php';  // diverse Klassen

trait OWSPLITProfile
{
    /**
     * Erzeugt alle benötigten Profile.
     */
    private function CreateProfile()
    {
        $this->RegisterProfileIntegerEx('OWSPLIT.Scanner', 'Gear', '', '', [
            [0, $this->Translate('standby'), '', -1],
            [1, $this->Translate('abort'), '', -1],
            [2, $this->Translate('scan'), '', -1],
            [3, $this->Translate('only playlists'), '', -1],
            [4, $this->Translate('completely'), '', -1]
        ]);
        $this->RegisterProfileInteger('OWSPLIT.ServerSelect.' . $this->InstanceID, 'Speaker', '', '', 0, 0, 0);
    }
}
/**
 * Definiert eine Datensatz zum Versenden an des OWSPLIT.
 *
 * @author        Michael Tröger <micha@nall-chan.net>
 * @copyright     2016 Michael Tröger
 * @license       https://creativecommons.org/licenses/by-nc-sa/4.0/ CC BY-NC-SA 4.0
 *
 * @version       1.0
 *
 * @example <b>Ohne</b>
 */
class OWSPLITData extends stdClass
{
    use UTF8Coder;

    /**
     * Adresse des Gerätes.
     *
     * @var string
     */
    public $Address;

    /**
     * Alle Kommandos als Array.
     *
     * @var string|array
     */
    public $Command;

    /**
     * Alle Daten des Kommandos.
     *
     * @var string|array
     */
    public $Data;

    /**
     * Flag ob auf Antwort gewartet werden muss.
     *
     * @var bool
     */
    public $needResponse;

    /**
     * Anzahl der versendeten Daten.
     *
     * @var int
     */
    private $SendValues;

    /**
     * Erzeugt ein Objekt vom Typ OWSPLITData.
     *
     * @param string|array $Command Kommando
     * @param string|array $Data Nutzdaten
     * @param bool $needResponse Auf Antwort warten.
     */
    public function __construct($Command = '', $Data = '', $needResponse = true)
    {
        $this->Address = '';
        if (is_array($Command)) {
            $this->Command = $Command;
        } else {
            $this->Command = [$Command];
        }
        if (is_array($Data)) {
            $this->Data = array_map('rawurlencode', $Data);
        } else {
            $this->Data = rawurlencode((string)$Data);
        }
        $this->needResponse = $needResponse;
    }

    /**
     * Erzeugt ein JSON-String für den internen Datenaustausch dieses Moduls.
     *
     * @param string $GUID GUID des Datenpaketes.
     *
     * @return string Der JSON-String.
     */
    public function ToJSONString($GUID)
    {
        $this->EncodeUTF8($this);
        return json_encode(['DataID'       => $GUID,
            'Address'                      => $this->Address,
            'Command'                      => $this->Command,
            'Data'                         => $this->Data,
            'needResponse'                 => $this->needResponse
        ]);
    }

    /**
     * Erzeugt einen String für den Datenaustausch mit einer IO-Instanz.
     *
     * @param type $GUID Die TX-GUID
     *
     * @return type Der JSON-String für den Datenaustausch.
     */
    public function ToJSONStringForOWSPLIT($GUID)
    {
        return json_encode(['DataID' => $GUID, 'Buffer' => utf8_encode($this->ToRawStringForOWSPLIT())]);
    }

    public function ToRawStringForOWSPLIT()
    {
        $Command = implode(' ', $this->Command);
        $this->SendValues = 0;

        if (is_array($this->Data)) {
            $Data = implode(' ', $this->Data);
            $this->SendValues = count($this->Data);
        } else {
            $Data = $this->Data;
            if (($this->Data !== null) && ($this->Data != '%3F')) {
                $this->SendValues = 1;
            }
        }
        return trim($this->Address . ' ' . trim($Command) . ' ' . trim($Data)) . chr(0x0d);
    }
}

class OWSPLITResponse extends OWSPLITData
{
    /**
     * Antwort ist vom OWSPLIT-Server.
     *
     * @static
     */
    const isServer = 0;

    /**
     * Antwort ist von einer MAC-Adresse.
     *
     * @static
     */
    const isMAC = 1;

    /**
     * Antwort ist von einer IP-Adresse.
     *
     * @static
     */
    const isIP = 2;

    /**
     * Enthält den Type des Versenders einer Antwort.
     *
     * @var int Kann ::isServer, ::isMAC oder ::isIP sein.
     */
    public $Device;

    /**
     * Zerlegt eine Antwort des OWSPLIT und erzeugt daraus ein OWSPLITResponse-Objekt.
     *
     * @param string $RawString
     */
    public function __construct(string $RawString)
    {
        $array = explode(' ', $RawString); // Antwortstring in Array umwandeln
        if (strpos($array[0], '%3A') == 2) { //isMAC
            $this->Device = self::isMAC;
            $this->Address = rawurldecode(array_shift($array));
        } elseif (strpos($array[0], '.')) { //isIP
            $this->Device = self::isIP;
            $this->Address = array_shift($array);
        } else { // isServer
            $this->Device = self::isServer;
            $this->Address = '';
        }
        $this->Command = [array_shift($array)];
        if ($this->Device == self::isServer) {
            if (count($array) != 0) {
                switch ($this->Command[0]) {
                    case 'player':
                    case 'alarm':
                    case 'favorites':
                    case 'pref':
                        $this->Command[1] = array_shift($array);
                        break;
                    case 'info':
                        $this->Command[1] = array_shift($array);
                        $this->Command[2] = array_shift($array);
                        break;
                    case 'playlists':
                        if (in_array($array[0], ['rename', 'delete', 'new', 'tracks', 'edit'])) {
                            $this->Command[1] = array_shift($array);
                        }
                        break;
                }
            }
        } else {
            if (count($array) != 0) {
                switch ($this->Command[0]) {
                    case 'mixer':
                    case 'playlist':
                    case 'playerpref':
                    case 'alarm':
                    case 'lma':
                    case 'live365':
                    case 'mp3tunes':
                    case 'pandora':
                    case 'podcast':
                    case 'radiotime':
                    case 'rhapsodydirect':
                    case 'picks':
                    case 'rss':
                        $this->Command[1] = array_shift($array);
                        break;
                    case 'shoutcast':
                        $this->Command[1] = array_shift($array);
                        if (in_array($array[0], ['play', 'load', 'insert', 'add'])) {
                            $this->Command[2] = array_shift($array);
                        }

                        break;
                    case 'prefset':
                    case 'status':
                        $this->Command[1] = array_shift($array);
                        $this->Command[2] = array_shift($array);
                        break;
                    case 'signalstrength':
                    case 'name':
                    case 'connected':
                    case 'client':
                    case 'sleep':
                    case 'button':
                    case 'power':
                    case 'play':
                    case 'stop':
                    case 'pause':
                    case 'mode':
                    case 'time':
                    case 'genre':
                    case 'artist':
                    case 'album':
                    case 'title':
                    case 'duration':
                    case 'remote':
                    case 'newmetadata':
                    case 'playlistcontrol':
                    case 'sync':
                    case 'display':
                    case 'show':
                    case 'randomplay':
                    case 'randomplaychoosegenre':
                    case 'randomplaygenreselectall':
                        break;
                    case 'displaynotify':
                    case 'menustatus':
                    default:
                        $this->Command[1] = $this->Command[0];
                        $this->Command[0] = 'ignore';
                        break;
                }
            }
        }
        if (count($array) == 0) {
            $array[0] = '';
        }

        $this->Data = array_map('rawurldecode', $array);
    }

    /**
     * Erzeugt aus dem Objekt einen JSON-String.
     *
     * @param string $GUID GUID welche in den JSON-String eingebunden wird.
     *
     * @return string Der JSON-String für den Datenaustausch
     */
    public function ToJSONStringForDevice(string $GUID)
    {
        $this->EncodeUTF8($this);
        return json_encode(['DataID'  => $GUID,
            'Address'                 => $this->Address,
            'Command'                 => $this->Command,
            'Data'                    => $this->Data
        ]);
    }

}


